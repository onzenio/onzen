<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

class MonitoringScheduler
{
    public function __construct(
        private readonly MonitoringRunService $runs,
        private readonly MonitoringCatalogService $catalog,
        private readonly QueryQuotaService $quota,
    ) {}

    public function triggerManual(
        Account $account,
        User $actor,
        string $definitionCode,
        ?int $clientId,
        string $idempotencyKey,
    ): MonitoringRun {
        if ($actor->role === UserRole::User) {
            throw new AuthorizationException('Disparo manual restrito a admin e operator.');
        }

        // Somente consulta: definição precisa de operação executável de consulta.
        $operation = $this->catalog->resolveExecutableOperation($definitionCode);

        if ($operation['type'] !== 'consultar') {
            throw new \RuntimeException("Definição {$definitionCode} fora do fluxo de consulta.");
        }

        $run = $this->runs->startOrGet($account, $idempotencyKey, [
            'client_id' => $clientId,
            'definition_code' => $definitionCode,
            'origin' => MonitoringRun::ORIGIN_MANUAL,
        ], $actor);

        // Repetição da mesma chave: devolve a existente sem novo tráfego.
        if (! $run->wasRecentlyCreated) {
            return $run;
        }

        $this->quota->reserve($account, $run, MonitoringRun::ORIGIN_MANUAL);

        ExecuteSerproJob::dispatch($run->id);

        return $run;
    }

    /**
     * Ciclo automático mensal: somente definições automáticas com operação
     * de consulta. Sem quota, a definição é pulada sem quebrar o ciclo.
     */
    public function runAutomaticCycle(Account $account): int
    {
        $period = QueryQuotaService::period();
        $dispatched = 0;

        $definitions = MonitoringDefinition::query()
            ->where('availability', MonitoringDefinition::AVAILABLE)
            ->where('automatic', true)
            ->get();

        foreach ($definitions as $definition) {
            if ($definition->executableOperation() === null) {
                continue;
            }

            $run = $this->runs->startOrGet($account, "cycle-{$period}-{$account->id}-{$definition->code}", [
                'definition_code' => $definition->code,
                'origin' => MonitoringRun::ORIGIN_AUTOMATIC,
            ]);

            if (! $run->wasRecentlyCreated) {
                continue;
            }

            try {
                $this->quota->reserve($account, $run, MonitoringRun::ORIGIN_AUTOMATIC);
            } catch (QuotaExhaustedException) {
                Log::info('monitoring.cycle_skipped_quota', [
                    'account_id' => $account->id,
                    'definition' => $definition->code,
                ]);

                continue;
            }

            ExecuteSerproJob::dispatch($run->id);
            $dispatched++;
        }

        return $dispatched;
    }
}
