<?php

namespace App\Services\Monitoring;

use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\PowerOfAttorney;
use App\Models\SerproRequestAuthor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Interpretation of the `OBTERPROCURACAO41` response and the local outorga
 * registry, ported from the legacy `ProcurationVerifier`.
 *
 * The official body carries eCAC service names (not codes):
 * `dados: [{dtexpiracao: "AAAAMMDD", nrsistemas, sistemas: [...]}]`. Names map
 * to codes through {@see ProcurationCatalog}; unknown names are ignored with a
 * log. Confirmed grants are persisted per Client and the verification result
 * is cached for {@see ProcurationCatalog::VERIFY_CACHE_TTL_HOURS} hours so a
 * known answer is not re-fetched on every execution.
 */
final class ProcurationVerifier
{
    public const REASON_PENDING = 'outorga_pendente';

    public const REASON_EXPIRED = 'outorga_expirada';

    public function __construct(private readonly ProcurationVerificationCall $live) {}

    /**
     * Verify a Client against the official service and the required codes of
     * a definition/operation.
     *
     * `verified = false` is a factual negative: `reason` distinguishes a code
     * never granted (`outorga_pendente`) from one whose validity lapsed
     * (`outorga_expirada`). An allowlist violation is refused with an explicit
     * error before any cache read or external call.
     *
     * @return array{
     *     verified: bool,
     *     reason: string|null,
     *     required_groups: list<list<string>>,
     *     missing_groups: list<list<string>>,
     *     granted: array<string, string>,
     *     from_cache: bool
     * }
     *
     * @throws SerproBlockedException
     */
    public function verify(
        Client $client,
        ?MonitoringDefinition $definition = null,
        ?string $operation = null,
        ?SerproRequestAuthor $author = null,
        ?CarbonInterface $at = null,
    ): array {
        $groups = self::requiredGroups(
            $definition?->procuration_codes,
            (string) ($definition?->getKey() ?? ''),
            (string) ($operation ?? ''),
        );

        self::assertAllowedCodes(self::flatten($groups));

        if ($groups === []) {
            return [
                'verified' => true,
                'reason' => null,
                'required_groups' => [],
                'missing_groups' => [],
                'granted' => [],
                'from_cache' => false,
            ];
        }

        $fetched = $this->grantsFor($client, $author, $at);
        $missing = $this->missingGroups($client, $groups, $at);

        return [
            'verified' => $missing === [],
            'reason' => $missing === [] ? null : $this->reasonFor($client, $missing),
            'required_groups' => $groups,
            'missing_groups' => $missing,
            'granted' => $fetched['granted'],
            'from_cache' => $fetched['from_cache'],
        ];
    }

    /**
     * Grants from the 24h cache, or a fresh official call persisted locally.
     *
     * An empty grant set is cached too: a known missing outorga must not
     * trigger a new external call inside the cache window.
     *
     * @return array{granted: array<string, string>, from_cache: bool}
     *
     * @throws SerproBlockedException
     */
    public function grantsFor(Client $client, ?SerproRequestAuthor $author = null, ?CarbonInterface $at = null): array
    {
        $cached = Cache::get(self::verifyCacheKey($client));

        if (is_array($cached)) {
            /** @var array<string, string> $cached */
            return ['granted' => $cached, 'from_cache' => true];
        }

        $granted = $this->live->fetchGrants($client, $author, $at);

        $this->applyGrants($client, $granted);
        $this->markVerified($client, $granted);

        return ['granted' => $granted, 'from_cache' => false];
    }

    public static function verifyCacheKey(Client $client): string
    {
        return 'serpro:procuracao_grants:'.hash('sha256', $client->account_id.'|'.$client->id);
    }

    public function recentlyVerified(Client $client): bool
    {
        return Cache::has(self::verifyCacheKey($client));
    }

    /**
     * @param  array<string, string>  $granted
     */
    public function markVerified(Client $client, array $granted = []): void
    {
        Cache::put(
            self::verifyCacheKey($client),
            $granted,
            now()->addHours(ProcurationCatalog::VERIFY_CACHE_TTL_HOURS),
        );
    }

    /**
     * Converte `dados` em código → expiração (maior `dtexpiracao` vence).
     *
     * @return array<string, string> código → `Y-m-d`
     */
    public static function parseGranted(array|string $dados): array
    {
        $granted = [];

        foreach (self::rows($dados) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $expires = self::parseExpiry((string) ($row['dtexpiracao'] ?? ''));

            if ($expires === null) {
                continue;
            }

            foreach (self::systems($row['sistemas'] ?? []) as $name) {
                $codes = ProcurationCatalog::codesForServiceName($name);

                if ($codes === []) {
                    Log::warning('procuracao_unknown_service_name', ['name' => mb_substr($name, 0, 120)]);

                    continue;
                }

                foreach ($codes as $code) {
                    if (! isset($granted[$code]) || $expires > $granted[$code]) {
                        $granted[$code] = $expires;
                    }
                }
            }
        }

        return $granted;
    }

    public static function parseExpiry(string $aaaammdd): ?string
    {
        $digits = preg_replace('/\D/', '', $aaaammdd) ?? '';

        if (strlen($digits) !== 8) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Ymd', $digits);
        } catch (\Throwable) {
            return null;
        }

        if ($date === false || $date->format('Ymd') !== $digits) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * Grupos de códigos exigidos: basta UM código válido por grupo (os pares
     * da tabela oficial valem como alternativa).
     *
     * @param  array<int, mixed>|null  $definitionCodes
     * @return list<list<string>>
     */
    public static function requiredGroups(?array $definitionCodes, string $definitionId, string $operation): array
    {
        $code = strtoupper(trim($operation));

        if (isset(ProcurationCatalog::OPERATION_CODES[$code])) {
            return [self::normalizeCodes(ProcurationCatalog::OPERATION_CODES[$code])];
        }

        if ($definitionCodes !== null && $definitionCodes !== []) {
            return [self::normalizeCodes($definitionCodes)];
        }

        if (ConsultCatalog::isParcelmentConsult($code) || str_starts_with($code, 'GERARDAS')) {
            $modality = ConsultCatalog::parcelmentModality($code);
            $codes = $modality !== null
                ? (ProcurationCatalog::codesForDefinition(strtolower($modality)) ?? [])
                : [];

            return $codes === [] ? [] : [self::normalizeCodes($codes)];
        }

        $family = ConsultCatalog::familyFor($code);
        $definition = $family !== null ? ProcurationCatalog::definitionForFamily($family) : null;
        $codes = $definition !== null
            ? (ProcurationCatalog::codesForDefinition($definition) ?? [])
            : (ProcurationCatalog::codesForDefinition($definitionId) ?? []);

        return $codes === [] ? [] : [self::normalizeCodes($codes)];
    }

    /**
     * Códigos exigidos para operar, achatados e sem repetição (os grupos da
     * tabela oficial valem como alternativa).
     *
     * @param  array<int, mixed>|null  $definitionCodes
     * @return list<string>
     */
    public static function requiredCodes(?array $definitionCodes, string $definitionId, string $operation): array
    {
        return array_values(array_unique(self::flatten(
            self::requiredGroups($definitionCodes, $definitionId, $operation),
        )));
    }

    /**
     * Código fora da allowlist oficial é recusado com erro explícito, antes
     * de qualquer cache ou chamada externa.
     *
     * @param  list<string>  $codes
     *
     * @throws SerproBlockedException
     */
    public static function assertAllowedCodes(array $codes): void
    {
        foreach ($codes as $code) {
            if (! ProcurationCatalog::isAllowedCode($code)) {
                throw new SerproBlockedException('procuracao_codigo_fora_da_allowlist');
            }
        }
    }

    public function holdsValidCode(Client $client, string $code, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return PowerOfAttorney::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->where('code', strtoupper(trim($code)))
            ->where('status', PowerOfAttorney::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $at))
            ->exists();
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function missingCodes(Client $client, array $codes, ?CarbonInterface $at = null): array
    {
        $missing = [];

        foreach ($codes as $code) {
            if (! $this->holdsValidCode($client, (string) $code, $at)) {
                $missing[] = (string) $code;
            }
        }

        return array_values($missing);
    }

    /**
     * @param  list<list<string>>  $groups
     * @return list<list<string>> grupos sem nenhum código válido
     */
    public function missingGroups(Client $client, array $groups, ?CarbonInterface $at = null): array
    {
        $missing = [];

        foreach ($groups as $group) {
            $valid = false;

            foreach ($group as $code) {
                if ($this->holdsValidCode($client, (string) $code, $at)) {
                    $valid = true;

                    break;
                }
            }

            if (! $valid) {
                $missing[] = array_values(array_map(strval(...), $group));
            }
        }

        return $missing;
    }

    /**
     * Persiste as outorgas confirmadas (`dtexpiracao` vira `valid_until`).
     *
     * @param  array<string, string>  $grants  código → `Y-m-d`
     * @return list<string> códigos atualizados
     */
    public function applyGrants(Client $client, array $grants): array
    {
        $updated = [];

        foreach ($grants as $code => $expires) {
            $code = strtoupper(trim((string) $code));

            if (! ProcurationCatalog::isAllowedCode($code)) {
                continue;
            }

            try {
                // `valid_until` é o fim do dia oficial (Brasília); normaliza
                // o instante para o timezone da aplicação antes de persistir.
                $validUntil = Carbon::parse((string) $expires, 'America/Sao_Paulo')
                    ->endOfDay()
                    ->setTimezone(config('app.timezone', 'UTC'));
            } catch (\Throwable) {
                continue;
            }

            PowerOfAttorney::query()
                ->withoutGlobalScope('account')
                ->updateOrCreate(
                    [
                        'account_id' => $client->account_id,
                        'client_id' => $client->id,
                        'code' => $code,
                    ],
                    [
                        'status' => PowerOfAttorney::STATUS_ACTIVE,
                        'valid_until' => $validUntil,
                    ],
                );

            $updated[] = $code;
        }

        return $updated;
    }

    /**
     * @param  list<list<string>>  $missingGroups
     */
    private function reasonFor(Client $client, array $missingGroups): string
    {
        foreach ($missingGroups as $group) {
            foreach ($group as $code) {
                $registered = PowerOfAttorney::query()
                    ->withoutGlobalScope('account')
                    ->where('account_id', $client->account_id)
                    ->where('client_id', $client->id)
                    ->where('code', $code)
                    ->exists();

                if (! $registered) {
                    return self::REASON_PENDING;
                }
            }
        }

        return self::REASON_EXPIRED;
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    private static function normalizeCodes(array $codes): array
    {
        $normalized = [];

        foreach ($codes as $code) {
            $value = strtoupper(trim((string) $code));

            if ($value !== '' && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<list<string>>  $groups
     * @return list<string>
     */
    private static function flatten(array $groups): array
    {
        $codes = [];

        foreach ($groups as $group) {
            foreach ($group as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Normaliza o payload em uma lista de linhas de outorga.
     *
     * @return array<int, mixed>
     */
    private static function rows(array|string $dados): array
    {
        if (is_string($dados)) {
            $decoded = json_decode($dados, true);
            $dados = is_array($decoded) ? $decoded : [];
        }

        if (array_key_exists('dtexpiracao', $dados)) {
            return [$dados];
        }

        $inner = $dados['dados'] ?? $dados['data'] ?? null;

        if (is_array($inner) || is_string($inner)) {
            return self::rows($inner);
        }

        return $dados;
    }

    /**
     * @return list<string>
     */
    private static function systems(mixed $systems): array
    {
        if (is_string($systems)) {
            $decoded = json_decode($systems, true);
            $systems = is_array($decoded) ? $decoded : [$systems];
        }

        if (! is_array($systems)) {
            return [];
        }

        $names = [];

        foreach ($systems as $name) {
            if (is_scalar($name) && (string) $name !== '') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
