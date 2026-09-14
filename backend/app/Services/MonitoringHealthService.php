<?php

namespace App\Services;

use App\Integrations\Serpro\SerproCredentialResolver;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproContract;

class MonitoringHealthService
{
    public const GATED = 'gated';

    public const CONFIGURED = 'configured';

    public const UNAVAILABLE = 'unavailable';

    public const DEGRADED = 'degraded';

    public function __construct(private readonly SerproCredentialResolver $credentials) {}

    /**
     * Estado derivado do gate efetivo (painel da Account A sobre ambiente),
     * nunca apenas das variáveis de ambiente.
     *
     * @return array{status: string, environment: string, source: string, transport_approved: bool, dry_run: bool, reason: string|null}
     */
    public function check(?Account $platformAccount = null): array
    {
        $panel = SerproContract::query()->first();
        $platformAccount ??= Account::query()->where('profile', 'A')->first();

        $environment = $panel?->environment ?? (string) config('monitoring.environment', 'homologacao');
        $source = $panel ? 'panel' : 'config';
        $approved = $panel?->transport_approved ?? false;
        $dryRun = (bool) config('monitoring.dry_run', true);

        if ($panel === null || ! $approved) {
            return $this->payload(self::GATED, $environment, $source, $approved, $dryRun, 'Transporte desligado no painel.');
        }

        if ($platformAccount === null || $this->credentials->resolveConsumer($platformAccount, $environment) === null) {
            return $this->payload(self::UNAVAILABLE, $environment, $source, $approved, $dryRun, 'Credencial do Contratante não resolve.');
        }

        $certificate = $platformAccount ? AccountCertificate::query()
            ->withoutGlobalScopes()
            ->where('account_id', $platformAccount->id)
            ->first() : null;

        if ($certificate === null || $certificate->isExpired()) {
            return $this->payload(self::DEGRADED, $environment, $source, $approved, $dryRun, 'Certificado Digital da plataforma ausente ou expirado.');
        }

        return $this->payload(self::CONFIGURED, $environment, $source, $approved, $dryRun, null);
    }

    /**
     * @return array{status: string, environment: string, source: string, transport_approved: bool, dry_run: bool, reason: string|null}
     */
    private function payload(string $status, string $environment, string $source, bool $approved, bool $dryRun, ?string $reason): array
    {
        return [
            'status' => $status,
            'environment' => $environment,
            'source' => $source,
            'transport_approved' => $approved,
            'dry_run' => $dryRun,
            'reason' => $reason,
        ];
    }
}
