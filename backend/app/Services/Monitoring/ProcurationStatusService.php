<?php

namespace App\Services\Monitoring;

use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\PowerOfAttorney;
use Illuminate\Support\Collection;

/**
 * Leitura de procurações da carteira (Task 20): estado por código a partir do
 * registro local + cache de verificação e a listagem de divergências da
 * Account (faltante, expirada ou divergente), com motivos factuais.
 *
 * Tudo é Account-scoped e read-only: nenhuma chamada externa e nenhum segredo
 * ou material de cofre é exposto.
 */
final class ProcurationStatusService
{
    public function __construct(private readonly ProcurationVerifier $verifier) {}

    /**
     * @return array{
     *     client: array{id: int, razao_social: string, cnpj: string},
     *     records: list<array<string, mixed>>,
     *     requirements: list<array<string, mixed>>,
     *     verification: array{cached: bool, granted: array<string, string>}
     * }
     */
    public function forClient(Client $client): array
    {
        $records = PowerOfAttorney::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->orderBy('code')
            ->get()
            ->map(fn (PowerOfAttorney $power): array => [
                'code' => (string) $power->code,
                'status' => (string) $power->status,
                'valid_from' => $power->valid_from?->toIso8601String(),
                'valid_until' => $power->valid_until?->toIso8601String(),
                'valid' => $power->isValid(),
                'divergent' => ! ProcurationCatalog::isAllowedCode((string) $power->code),
            ])
            ->values()
            ->all();

        $requirements = [];

        foreach ($this->currentDefinitions($client) as $definition) {
            if (! $definition->requires_procuracao) {
                continue;
            }

            $groups = $this->groupsFor($definition);

            if ($groups === []) {
                if ($definition->requires_procuracao) {
                    $requirements[] = [
                        'definition_id' => (string) $definition->getKey(),
                        'codes' => [],
                        'satisfied' => false,
                        'reason' => ProcurationVerifier::REASON_UNRESOLVED,
                    ];
                }

                continue;
            }

            $missing = $this->verifier->missingGroups($client, $groups);

            $requirements[] = [
                'definition_id' => (string) $definition->getKey(),
                'codes' => $this->flatten($groups),
                'satisfied' => $missing === [],
                'reason' => $missing === [] ? null : $this->verifier->reasonForMissing($client, $missing),
            ];
        }

        $cached = $this->verifier->cachedGrants($client);

        return [
            'client' => [
                'id' => (int) $client->getKey(),
                'razao_social' => (string) $client->razao_social,
                'cnpj' => (string) $client->cnpj,
            ],
            'records' => $records,
            'requirements' => $requirements,
            'verification' => [
                'cached' => $cached !== null,
                'granted' => $cached ?? [],
            ],
        ];
    }

    /**
     * Clients of the Account with a missing, expired or divergent outorga.
     * Factual reasons: `outorga_pendente` (no local row for a required code),
     * `outorga_expirada` (row exists but lapsed), `outorga_divergente` (code
     * outside the official allowlist) and `procuracao_requisito_nao_resolvido`
     * (requirement flagged without a resolvable code).
     *
     * @return array{table_version: string, items: list<array<string, mixed>>}
     */
    public function divergences(int $accountId): array
    {
        /** @var array<int, array{client: Client, reasons: list<string>, codes: list<string>}> $byClient */
        $byClient = [];

        $enrollments = MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->whereIn('status', [MonitoringEnrollment::STATUS_ACTIVE, MonitoringEnrollment::STATUS_PAUSED])
            ->with(['client', 'definition'])
            ->orderBy('client_id')
            ->get();

        foreach ($enrollments as $enrollment) {
            $client = $enrollment->client;
            $definition = $enrollment->definition;

            if ($client === null || $definition === null) {
                continue;
            }

            if (! $definition->requires_procuracao) {
                continue;
            }

            $groups = $this->groupsFor($definition);

            if ($groups === []) {
                if ($definition->requires_procuracao) {
                    $this->append($byClient, $client, ProcurationVerifier::REASON_UNRESOLVED, []);
                }

                continue;
            }

            $missing = $this->verifier->missingGroups($client, $groups);

            if ($missing === []) {
                continue;
            }

            $this->append(
                $byClient,
                $client,
                $this->verifier->reasonForMissing($client, $missing),
                $this->flatten($missing),
            );
        }

        $divergent = PowerOfAttorney::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->get()
            ->filter(fn (PowerOfAttorney $power): bool => ! ProcurationCatalog::isAllowedCode((string) $power->code));

        foreach ($divergent as $power) {
            $client = $this->clientFor($accountId, (int) $power->client_id);

            if ($client === null) {
                continue;
            }

            $this->append($byClient, $client, 'outorga_divergente', [(string) $power->code]);
        }

        ksort($byClient);

        return [
            'table_version' => ProcurationCatalog::TABLE_VERSION,
            'items' => array_values(array_map(
                fn (array $item): array => [
                    'client' => [
                        'id' => (int) $item['client']->getKey(),
                        'razao_social' => (string) $item['client']->razao_social,
                        'cnpj' => (string) $item['client']->cnpj,
                    ],
                    'reasons' => array_values(array_unique($item['reasons'])),
                    'codes' => array_values(array_unique($item['codes'])),
                ],
                $byClient,
            )),
        ];
    }

    /**
     * @return Collection<int, MonitoringDefinition>
     */
    private function currentDefinitions(Client $client): Collection
    {
        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->whereIn('status', [MonitoringEnrollment::STATUS_ACTIVE, MonitoringEnrollment::STATUS_PAUSED])
            ->with('definition')
            ->get()
            ->pluck('definition')
            ->filter()
            ->unique(fn (MonitoringDefinition $definition): string => (string) $definition->getKey())
            ->values();
    }

    /**
     * Required groups of a definition, using the definition fallback (the
     * operation is unknown until a run exists).
     *
     * @return list<list<string>>
     */
    private function groupsFor(MonitoringDefinition $definition): array
    {
        return ProcurationVerifier::requiredGroups(
            $definition->procuration_codes,
            (string) $definition->getKey(),
            '',
        );
    }

    private function clientFor(int $accountId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->whereKey($clientId)
            ->first();
    }

    /**
     * @param  array<int, array{client: Client, reasons: list<string>, codes: list<string>}>  $byClient
     * @param  list<string>  $codes
     */
    private function append(array &$byClient, Client $client, string $reason, array $codes): void
    {
        $id = (int) $client->getKey();

        $byClient[$id] ??= ['client' => $client, 'reasons' => [], 'codes' => []];
        $byClient[$id]['reasons'][] = $reason;

        foreach ($codes as $code) {
            $byClient[$id]['codes'][] = (string) $code;
        }
    }

    /**
     * @param  list<list<string>>  $groups
     * @return list<string>
     */
    private function flatten(array $groups): array
    {
        $codes = [];

        foreach ($groups as $group) {
            foreach ($group as $code) {
                $codes[] = (string) $code;
            }
        }

        return array_values(array_unique($codes));
    }
}
