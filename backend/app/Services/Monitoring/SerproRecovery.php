<?php

namespace App\Services\Monitoring;

use App\Enums\MonitoringRunStatus;
use App\Enums\SerproActionStatus;
use App\Exceptions\InvalidRunTransitionException;
use App\Models\MonitoringRun;
use App\Models\SerproServiceRequest;
use Illuminate\Support\Carbon;

/**
 * Recupera execuções e ações SERPRO interrompidas pela perda do worker.
 *
 * A janela de processamento (config) é o coração observável: entrega
 * concorrente dentro da janela encontra o trabalho ainda válido e aguarda o
 * worker ativo; além da janela, o trabalho sai de `running` sem repetir a
 * solicitação externa original — com protocolo persistido retoma só o
 * polling, sem protocolo encerra com motivo operacional factual.
 */
class SerproRecovery
{
    public const ERROR_WORKER_INTERRUPTED = 'worker_interrupted';

    public function windowSeconds(): int
    {
        return max(1, (int) config('monitoring.recovery.processing_window', 600));
    }

    public function isStale(Carbon $updatedAt): bool
    {
        return $updatedAt->diffInSeconds(now()) > $this->windowSeconds();
    }

    public function recoverRun(MonitoringRun $run): MonitoringRun
    {
        $run->refresh();

        if ($run->status !== MonitoringRunStatus::Running) {
            return $run;
        }

        if (! $this->isStale($run->updated_at)) {
            return $run;
        }

        try {
            if (trim((string) $run->protocol) !== '') {
                return $run->transitionTo(MonitoringRunStatus::AwaitingProtocol, [
                    'error_code' => self::ERROR_WORKER_INTERRUPTED,
                ]);
            }

            return $run->transitionTo(MonitoringRunStatus::Expired, [
                'error_code' => self::ERROR_WORKER_INTERRUPTED,
            ]);
        } catch (InvalidRunTransitionException) {
            // Outro worker assentou o run primeiro; nada factual a marcar.
            return $run->refresh();
        }
    }

    public function recoverAction(SerproServiceRequest $action): SerproServiceRequest
    {
        $action->refresh();

        if ($action->status !== SerproActionStatus::Running) {
            return $action;
        }

        if (! $this->isStale($action->updated_at)) {
            return $action;
        }

        if (trim((string) $action->protocol) !== '') {
            return $this->persistAction($action, SerproActionStatus::Pending);
        }

        return $this->persistAction($action, SerproActionStatus::Expired);
    }

    private function persistAction(SerproServiceRequest $action, SerproActionStatus $status): SerproServiceRequest
    {
        $action->forceFill([
            'status' => $status,
            'metadata' => [...(array) $action->metadata, 'error_code' => self::ERROR_WORKER_INTERRUPTED],
        ])->save();

        return $action;
    }
}
