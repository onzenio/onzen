<?php

namespace App\Console\Commands;

use App\Exceptions\SerproBlockedException;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Services\Monitoring\MonitoringScheduler;
use App\Support\CurrentAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo automático mensal (Task 18 / design decision 6).
 *
 * Seleciona apenas associações ativas cujo Client tem Monitoring Status
 * ativo e cuja definição está marcada como `automatic` no catálogo semeado;
 * dispara somente operações de consulta com origem `automatic`, reservando
 * quota por execução. Preview por padrão (`--confirm` para agendar de fato).
 *
 * O comando é global: a quota é por Account, então uma Account esgotada
 * entra como `blocked`, suas demais associações são puladas e a execução
 * segue nas outras Accounts (ids esgotados saem em `exhausted_accounts`).
 * O `--limit` corta apenas linhas já elegíveis e a saída sinaliza
 * `truncated: true` quando o teto foi atingido com linhas restantes.
 */
class RunMonthlyMonitoringCycle extends Command
{
    protected $signature = 'monitoring:run-monthly-cycle
        {--limit=500 : Número máximo de associações elegíveis consideradas}
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
        /** @var array<int, true> $exhaustedAccounts */
        $exhaustedAccounts = [];
        $considered = 0;
        $truncated = false;

        // Eligibility that can live in SQL is pushed down so the cap counts
        // only rows the cycle can actually consider: an active association of
        // an active Client under an automatic production definition.
        // Console varre TODAS as Accounts por design: sem CurrentAccount o
        // escopo fail-closed esconderia tudo. Tenancy segue por linha nos
        // services (scheduler/enrollment já operam sem escopo global).
        $query = MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->with(['client', 'definition'])
            ->where('status', MonitoringEnrollment::STATUS_ACTIVE)
            ->whereIn('definition_id', $definitionIds)
            ->whereHas('client', function (Builder $client): void {
                $client->withoutGlobalScope('account')->where('monitoring_enabled', true);
            });

        $matched = (int) $query->count();

        foreach ($query->lazyById(100) as $enrollment) {
            if ($considered >= $limit) {
                $truncated = $matched > $considered;
                break;
            }
            $considered++;

            $accountId = (int) $enrollment->account_id;

            // Contexto de tenant por linha: os services (ineligibility,
            // quota, executor) leem via escopo/account efetiva; sem isto o
            // fail-closed esconderia client/quota no console. As relations
            // vindas no `with()` da query global nasceram com escopo vazio e
            // precisam ser recarregadas neste contexto.
            CurrentAccount::set($accountId);
            $enrollment->unsetRelation('client')->unsetRelation('definition');

            if (! $confirm) {
                $scheduler->ineligibility($enrollment, MonitoringRun::TRIGGER_AUTOMATIC) === null
                    ? $counts['queued']++
                    : $counts['skipped']++;

                continue;
            }

            // Quota is per Account: an exhausted Account cannot reserve for
            // its remaining enrollments, but every other Account still can.
            if (isset($exhaustedAccounts[$accountId])) {
                $counts['blocked']++;

                continue;
            }

            try {
                $run = $scheduler->schedule($enrollment, MonitoringRun::TRIGGER_AUTOMATIC);
                $run->wasRecentlyCreated ? $counts['queued']++ : $counts['skipped']++;
            } catch (ValidationException) {
                $counts['blocked']++;
                $exhaustedAccounts[$accountId] = true;
            } catch (SerproBlockedException) {
                $counts['skipped']++;
            }
        }

        $this->line((string) json_encode([
            'dry_run' => ! $confirm,
            'queued' => $counts['queued'],
            'blocked' => $counts['blocked'],
            'skipped' => $counts['skipped'],
            'exhausted_accounts' => array_keys($exhaustedAccounts),
            'truncated' => $truncated,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
