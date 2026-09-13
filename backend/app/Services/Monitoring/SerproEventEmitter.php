<?php

namespace App\Services\Monitoring;

use App\Contracts\SerproEvents;
use App\Events\Monitoring\SerproActionFinished;
use App\Events\Monitoring\SerproRunFinished;
use App\Events\Monitoring\SerproRunStarted;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Support\Redactor;
use DateTimeInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emissor dos eventos operacionais do monitoramento (Task 19).
 *
 * Choice: typed event classes dispatched through the Laravel event bus plus a
 * structured `Log` record, never NATS/broadcast. The typed events give the
 * future listeners (audit, alerting) a stable payload, and the log line is the
 * durable operational trace.
 *
 * Payload policy: only opaque identifiers and factual execution metadata
 * (ids, definition/operation codes, trigger, environment, dry-run, status,
 * attempt, timestamps, reason codes). PFX, passwords, tokens, XML and fiscal
 * payload bodies never enter the context; free-form error reasons pass through
 * {@see Redactor::text()} first.
 *
 * Best-effort by design: a broken log channel or a throwing listener is
 * swallowed and recorded as a technical log only, so event emission can never
 * break a run. Callers that need defence against a broken emitter itself wrap
 * the call too (see {@see SerproExecutor::emit()}).
 */
final class SerproEventEmitter implements SerproEvents
{
    public function runStarted(MonitoringRun $run): void
    {
        $this->emit('serpro_run_started', function () use ($run): void {
            $context = [
                ...$this->runContext($run),
                'status' => $run->status->value,
                'attempt' => $this->nextAttempt($run),
                'started_at' => $run->started_at?->toIso8601String(),
            ];

            Log::info('serpro_run_started', $context);
            Event::dispatch(new SerproRunStarted($context));
        });
    }

    public function runFinished(MonitoringRun $run, ?string $reason = null): void
    {
        $this->emit('serpro_run_finished', function () use ($run, $reason): void {
            $context = [
                ...$this->runContext($run),
                'status' => $run->status->value,
                'attempt' => $this->lastAttempt($run),
                'error_code' => $run->error_code,
                'reason' => Redactor::text($reason),
                'retryable' => ! $run->status->isTerminal(),
                'terminal' => $run->status->isTerminal(),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'duration_ms' => $this->durationMs($run),
            ];

            Log::info('serpro_run_finished', $context);
            Event::dispatch(new SerproRunFinished($context));
        });
    }

    public function actionFinished(
        string $requestId,
        string $accountId,
        string $operationCode,
        string $status,
        ?string $clientId = null,
        ?string $errorCode = null,
        ?string $reason = null,
        bool $retryable = false,
        bool $terminal = true,
        ?DateTimeInterface $finishedAt = null,
    ): void {
        $this->emit('serpro_action_finished', function () use (
            $requestId,
            $accountId,
            $clientId,
            $operationCode,
            $status,
            $errorCode,
            $reason,
            $retryable,
            $terminal,
            $finishedAt,
        ): void {
            $context = [
                'request_id' => $requestId,
                'account_id' => $accountId,
                'client_id' => $clientId,
                'operation_code' => $operationCode,
                'status' => $status,
                'error_code' => $errorCode,
                'reason' => Redactor::text($reason),
                'retryable' => $retryable,
                'terminal' => $terminal,
                'finished_at' => $finishedAt?->format(DateTimeInterface::ATOM),
            ];

            Log::info('serpro_action_finished', $context);
            Event::dispatch(new SerproActionFinished($context));
        });
    }

    /**
     * @param  callable(): void  $emission
     */
    private function emit(string $event, callable $emission): void
    {
        try {
            $emission();
        } catch (Throwable $exception) {
            $this->logFailure($event, $exception);
        }
    }

    private function logFailure(string $event, Throwable $exception): void
    {
        try {
            Log::error('serpro_event_emission_failed', [
                'event' => $event,
                'error' => Redactor::text($exception->getMessage()),
            ]);
        } catch (Throwable) {
            // A broken log channel must never break the execution either.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runContext(MonitoringRun $run): array
    {
        $enrollment = $this->enrollmentFor($run);
        $clientId = $enrollment?->client_id;

        return [
            'run_id' => (string) $run->id,
            'account_id' => (string) $run->account_id,
            'client_id' => $clientId === null ? null : (string) $clientId,
            'enrollment_id' => (string) $run->enrollment_id,
            'definition_key' => (string) $run->definition_key,
            'operation_code' => (string) ($run->operation_code ?? ''),
            'trigger' => (string) $run->trigger,
            'environment' => (string) $run->environment,
            'dry_run' => (bool) $run->dry_run,
        ];
    }

    private function enrollmentFor(MonitoringRun $run): ?MonitoringEnrollment
    {
        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->whereKey($run->enrollment_id)
            ->first();
    }

    private function nextAttempt(MonitoringRun $run): int
    {
        return max(1, ((int) $run->attempts()->max('attempt')) + 1);
    }

    private function lastAttempt(MonitoringRun $run): int
    {
        return max(1, (int) $run->attempts()->max('attempt'));
    }

    private function durationMs(MonitoringRun $run): ?int
    {
        if ($run->started_at === null || $run->finished_at === null) {
            return null;
        }

        return max(0, (int) round($run->started_at->diffInMilliseconds($run->finished_at, false)));
    }
}
