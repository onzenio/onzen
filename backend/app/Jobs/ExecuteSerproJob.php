<?php

namespace App\Jobs;

use App\Models\MonitoringRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteSerproJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public readonly int $monitoringRunId)
    {
        $this->onConnection('serpro');
        $this->onQueue('serpro');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $base = (int) config('monitoring.limits.backoff_base_seconds', 60);

        return [$base, $base * 5, $base * 15, $base * 30];
    }

    public function handle(): void
    {
        $run = MonitoringRun::query()->withoutGlobalScopes()->find($this->monitoringRunId);

        if ($run === null || $run->status !== MonitoringRun::PENDING) {
            return;
        }

        $run->transitionTo(MonitoringRun::RUNNING);
    }
}
