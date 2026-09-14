<?php

namespace App\Services;

use App\Events\MonitoringRunFinished;
use App\Events\MonitoringRunStarted;
use App\Models\MonitoringRun;
use Illuminate\Support\Facades\Log;

class MonitoringEventService
{
    /**
     * Eventos operacionais carregam apenas identificadores opacos.
     * PFX, senhas, tokens, XML e conteúdo fiscal nunca entram no payload.
     */
    public function started(MonitoringRun $run): void
    {
        $payload = [
            'event' => 'monitoring.run.started',
            'run_id' => $run->id,
            'account_id' => $run->account_id,
            'client_id' => $run->client_id,
            'definition_code' => $run->definition_code,
            'origin' => $run->origin,
        ];

        Log::info('monitoring.run.started', $payload);
        MonitoringRunStarted::dispatch($payload);
    }

    public function finished(MonitoringRun $run, string $outcome, ?string $error = null): void
    {
        $payload = array_filter([
            'event' => 'monitoring.run.finished',
            'run_id' => $run->id,
            'account_id' => $run->account_id,
            'client_id' => $run->client_id,
            'definition_code' => $run->definition_code,
            'origin' => $run->origin,
            'outcome' => $outcome,
            'error' => $error !== null ? substr($error, 0, 200) : null,
        ], fn ($v) => $v !== null);

        Log::info('monitoring.run.finished', $payload);
        MonitoringRunFinished::dispatch($payload);
    }
}
