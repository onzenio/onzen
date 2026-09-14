<?php

namespace App\Services;

use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PgdasChainService
{
    public function __construct(
        private readonly MonitoringRunService $runs,
        private readonly SnapshotService $snapshots,
        private readonly QueryQuotaService $quota,
    ) {}

    /**
     * Encadeia índice→declaração→extrato quando o índice traz novidade.
     * Idempotente por período: sem novidade, nada é enfileirado.
     *
     * @param array<string, mixed> $indexPayload
     * @return list<string> operações enfileiradas
     */
    public function chainFromIndex(
        Account $account,
        Client $client,
        array $indexPayload,
        ?User $actor = null,
    ): array {
        $snapshot = $this->snapshots->record($account, $client, 'pgdasd-indice', [
            'normalized' => true,
            'family' => 'pgdasd-indice',
            'periodos' => $this->periods($indexPayload),
        ]);

        if (! $snapshot->wasRecentlyCreated) {
            return [];
        }

        $enqueued = [];

        foreach ($this->periods($indexPayload) as $periodo) {
            foreach (['consultar-pgdasd-declaracao', 'consultar-pgdasd-extrato'] as $operation) {
                $run = $this->runs->startOrGet($account, "pgdasd-{$client->id}-{$periodo}-{$operation}", [
                    'client_id' => $client->id,
                    'definition_code' => 'pgdasd',
                    'origin' => MonitoringRun::ORIGIN_AUTOMATIC,
                ], $actor);

                if (! $run->wasRecentlyCreated) {
                    continue;
                }

                try {
                    $this->quota->reserve($account, $run, MonitoringRun::ORIGIN_AUTOMATIC);
                } catch (QuotaExhaustedException) {
                    Log::info('monitoring.pgdas_chain_skipped_quota', [
                        'account_id' => $account->id, 'periodo' => $periodo,
                    ]);

                    continue;
                }

                ExecuteSerproJob::dispatch($run->id);
                $enqueued[] = "{$operation}:{$periodo}";
            }
        }

        return $enqueued;
    }

    /**
     * @param array<string, mixed> $indexPayload
     * @return list<string>
     */
    private function periods(array $indexPayload): array
    {
        $periods = $indexPayload['periodos_disponiveis'] ?? [];

        foreach ($indexPayload['declaracoes'] ?? [] as $dec) {
            foreach ($dec['periodos'] ?? [] as $periodo) {
                $periods[] = $periodo;
            }
        }

        return array_values(array_unique($periods));
    }
}
