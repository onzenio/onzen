<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\MonitoringScheduler;
use Illuminate\Console\Command;

class RunMonitoringCycle extends Command
{
    protected $signature = 'monitoring:cycle {--account= : Executa o ciclo para uma Account específica}';

    protected $description = 'Ciclo automático mensal de consultas SERPRO (somente consulta).';

    public function handle(MonitoringScheduler $scheduler): int
    {
        $query = Account::query()->withoutGlobalScopes();

        if ($this->option('account')) {
            $query->whereKey($this->option('account'));
        }

        $total = 0;

        foreach ($query->get() as $account) {
            $total += $scheduler->runAutomaticCycle($account);
        }

        $this->info("Ciclo concluído: {$total} consultas disparadas.");

        return self::SUCCESS;
    }
}
