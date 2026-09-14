<?php

namespace App\Services\Monitoring;

use App\Contracts\VaultResolver;
use App\Enums\AccountProfile;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Integrations\Serpro\SerproCredentials;
use App\Models\Account;
use App\Models\SerproContract;
use App\Models\SerproSettings;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administração SERPRO da Account A: leitura factual do gate e das
 * credenciais, rotação de credenciais no cofre, alternância de ambiente e
 * interruptor de transporte.
 *
 * Nenhum segredo, PFX ou senha sai daqui: o overview resolve a credencial
 * apenas para mascarar o identificador e sinalizar presença de material de
 * mTLS; as mutações auditan somente ambiente e identificador mascarado. A
 * troca de credenciais invalida o cache de token do ref anterior e remove o
 * valor antigo do cofre.
 */
class SerproAdminService
{
    public function __construct(
        private readonly SerproTransportGate $gate,
        private readonly SerproCredentialResolver $resolver,
        private readonly VaultResolver $vault,
        private readonly OAuthTokenCache $tokenCache,
        private readonly AuditService $audit,
    ) {}

    /**
     * Estado factual do painel, sem efeitos colaterais: não cria a linha de
     * `serpro_settings` (o fallback de `.env` permanece vivo até uma decisão
     * explícita) e nunca devolve segredo, PFX, senha ou ref crua.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $credentials = [];

        foreach (SerproTransportGate::ENVIRONMENTS as $environment) {
            $credentials[$environment] = $this->credentialOverview($environment);
        }

        return [
            'environment' => $this->gate->environment(),
            'transport' => [
                'state' => $this->gate->transportState(),
                'open' => $this->gate->isTransportOpen(),
                'dry_run' => $this->gate->isDryRun(),
            ],
            'credential_version' => (int) config('monitoring.credential_version', 1),
            'credentials' => $credentials,
        ];
    }

    /**
     * Replace the Contratante credential of the target environment.
     *
     * The previous value is purged from the vault and its cached OAuth token
     * is dropped, so a fresh token is always minted after a rotation.
     *
     * @param  array{client_id: string, consumer_secret: string, contratante_doc?: string|null, certificate?: string|null, certificate_password?: string|null}  $payload
     */
    public function replaceCredentials(User $actor, string $environment, array $payload): SerproContract
    {
        $previous = SerproContract::query()->where('environment', $environment)->first();
        $previousRef = $previous?->credential_ref;

        $ref = $this->newRef($environment);

        $this->vault->put($ref, array_filter([
            'client_id' => $payload['client_id'],
            'consumer_secret' => $payload['consumer_secret'],
            'contratante_doc' => $payload['contratante_doc'] ?? null,
            'certificate' => $payload['certificate'] ?? null,
            'certificate_password' => $payload['certificate_password'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        try {
            $credentials = $this->resolver->resolveRef($ref);
        } catch (SerproBlockedException) {
            $this->vault->forget($ref);

            throw ValidationException::withMessages([
                'client_id' => ['As credenciais informadas são inválidas.'],
            ]);
        }

        $contract = SerproContract::query()->updateOrCreate(
            ['environment' => $environment],
            ['credential_ref' => $ref, 'updated_by_user_id' => $actor->id],
        );

        if (is_string($previousRef) && $previousRef !== '' && $previousRef !== $ref) {
            $this->vault->forget($previousRef);
            $this->tokenCache->forget($environment, $previousRef);
        }

        $this->tokenCache->forget($environment, $ref);

        $this->audit->record($actor, $this->platformAccount()?->id, null, 'platform.serpro_credentials_rotated', [
            'environment' => $environment,
            'identifier' => self::maskIdentifier($credentials->eCnpj),
            'has_certificate' => $this->hasCertificate($credentials),
        ]);

        return $contract->refresh();
    }

    public function switchEnvironment(User $actor, string $environment, ?string $evidence = null): SerproSettings
    {
        // O ambiente efetivo é lido ANTES de qualquer criação de linha: sem
        // painel, `SerproSettings::current()` nasceria com o default
        // `homologacao` e mascararia um `.env` em produção (um switch real
        // producao → homologacao viraria no-op sem audit).
        $previous = $this->gate->environment();

        $settings = $this->panelSettings($previous);

        if ($previous === $environment) {
            return $settings;
        }

        $settings->update(['environment' => $environment]);

        $this->audit->record($actor, $this->platformAccount()?->id, null, 'platform.serpro_environment_switched', [
            'before' => $previous,
            'after' => $environment,
            'evidence' => $evidence,
            'transport_open' => $this->gate->isTransportOpen(),
        ]);

        return $settings->refresh();
    }

    public function setTransport(User $actor, bool $enabled, ?string $evidence = null): SerproSettings
    {
        // Mesma regra: o ambiente efetivo é capturado antes da primeira
        // escrita e a linha nasce preservando-o, senão o POST de transporte
        // trocaria o ambiente autoritativo silenciosamente.
        $environment = $this->gate->environment();

        $settings = $this->panelSettings($environment);
        $before = $settings->transportApproved();

        $settings->update([
            'transport_approved' => $enabled,
            'transport_approved_at' => $enabled ? now() : null,
            'transport_approved_by_user_id' => $enabled ? $actor->id : null,
        ]);

        $this->audit->record($actor, $this->platformAccount()?->id, null, 'platform.serpro_transport_toggled', [
            'before' => $before,
            'after' => $enabled,
            'environment' => $environment,
            'evidence' => $enabled ? $evidence : null,
        ]);

        return $settings->refresh();
    }

    /**
     * @param  list<string>  $reasons
     */
    public function recordEnvironmentRefusal(?User $actor, ?string $requestedEnvironment, array $reasons): void
    {
        $this->audit->record($actor, $this->platformAccount()?->id, null, 'platform.serpro_environment_switch_refused', [
            'requested_environment' => $requestedEnvironment,
            'reasons' => array_values($reasons),
        ]);
    }

    /**
     * @param  list<string>  $reasons
     */
    public function recordTransportRefusal(?User $actor, bool $enabled, array $reasons): void
    {
        $this->audit->record($actor, $this->platformAccount()?->id, null, 'platform.serpro_transport_refused', [
            'enabled' => $enabled,
            'environment' => $this->gate->environment(),
            'reasons' => array_values($reasons),
        ]);
    }

    /**
     * @return array{present: bool, resolved: bool, identifier: string|null, has_certificate: bool, version: int, updated_at: string|null}
     */
    private function credentialOverview(string $environment): array
    {
        $contract = SerproContract::query()->where('environment', $environment)->first();
        $ref = $contract?->credential_ref;
        $present = is_string($ref) && $ref !== '';

        $credentials = null;

        if ($present) {
            try {
                $credentials = $this->resolver->resolveRef($ref);
            } catch (SerproBlockedException) {
                $credentials = null;
            }
        }

        return [
            'present' => $present,
            'resolved' => $credentials !== null,
            'identifier' => $credentials === null ? null : self::maskIdentifier($credentials->eCnpj),
            'has_certificate' => $credentials !== null && $this->hasCertificate($credentials),
            'version' => (int) config('monitoring.credential_version', 1),
            'updated_at' => $contract?->updated_at?->toIso8601String(),
        ];
    }

    private function hasCertificate(SerproCredentials $credentials): bool
    {
        return is_string($credentials->certificate) && $credentials->certificate !== ''
            && is_string($credentials->certificatePassword) && $credentials->certificatePassword !== '';
    }

    public static function maskIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $length = mb_strlen($identifier);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).mb_substr($identifier, -4);
    }

    private function newRef(string $environment): string
    {
        return 'secret:serpro-contratante-'.$environment.'-'.strtolower((string) Str::ulid());
    }

    /**
     * Painel singleton: quando a primeira decisão é gravada, a linha nasce
     * com o ambiente efetivo do gate (painel ainda inexistente), nunca com um
     * default que sobrescreva o `.env` vigente.
     */
    private function panelSettings(string $effectiveEnvironment): SerproSettings
    {
        return SerproSettings::query()->firstOrCreate([], [
            'environment' => $effectiveEnvironment,
            'transport_approved' => false,
        ]);
    }

    private function platformAccount(): ?Account
    {
        return Account::query()
            ->where('profile', AccountProfile::A)
            ->orderBy('id')
            ->first();
    }
}
