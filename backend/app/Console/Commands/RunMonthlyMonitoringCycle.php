<?php

namespace App\Console\Commands;

use App\Exceptions\SerproBlockedException;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Services\Monitoring\MonitoringScheduler;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo automático mensal (Task 18 / design decision 6).
 *
 * Seleciona apenas associações ativas cujo Client tem Monitoring Status
 * ativo e cuja definição está marcada como `automatic` no catálogo semeado;
 * dispara somente operações de consulta com origem `automatic`, reservando
 * quota por execução. Preview por padrão (`--confirm` para agendar de fato).
 *
 * Uma quota esgotada interrompe o lote de forma limpa: a associação da vez
 * entra como `blocked`, as restantes como `skipped`, e o comando termina com
 * sucesso — nunca 500.
 */
class RunMonthlyMonitoringCycle extends Command
{
    protected $signature = 'monitoring:run-monthly-cycle
        {--limit=500 : Número máximo de associações consideradas}
        {--confirm : Agenda de fato; sem a flag apenas simula}';

    protected $description = 'Agenda o ciclo automático mensal (somente definições automáticas de consulta).';

    public function handle(MonitoringScheduler $scheduler): int
    {
        $confirm = (bool) $this->option('confirm');
        $limit = max(1, min((int) $this->option('limit'), 5000));

        $definitionIds = MonitoringDefinition::query()
            ->where('strategy', MonitoringDefinition::STRATEGY_AUTOMATIC)
            ->where('is_active', true)
            ->where('availability', MonitoringDefinition::AVAILABILITY_PRODUCTION)
            ->pluck('id')
            ->all();

        $counts = ['queued' => 0, 'blocked' => 0, 'skipped' => 0];
        $stop = false;
        $considered = 0;

        $query = MonitoringEnrollment::query()
            ->with(['client', 'definition'])
            ->where('status', MonitoringEnrollment::STATUS_ACTIVE)
            ->whereIn('definition_id', $definitionIds);

        foreach ($query->lazyById(100) as $enrollment) {
            if ($considered >= $limit) {
                break;
            }
            $considered++;

            if ($stop) {
                $counts['skipped']++;

                continue;
            }

            if (! $confirm) {
                $scheduler->ineligibility($enrollment, MonitoringRun::TRIGGER_AUTOMATIC) === null
                    ? $counts['queued']++
                    : $counts['skipped']++;

                continue;
            }

            try {
                $run = $scheduler->schedule($enrollment, MonitoringRun::TRIGGER_AUTOMATIC);
                $run->wasRecentlyCreated ? $counts['queued']++ : $counts['skipped']++;
            } catch (ValidationException) {
                $counts['blocked']++;
                $stop = true;
            } catch (SerproBlockedException) {
                $counts['skipped']++;
            }
        }

        $this->line((string) json_encode([
            'dry_run' => ! $confirm,
            'queued' => $counts['queued'],
            'blocked' => $counts['blocked'],
            'skipped' => $counts['skipped'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
