<?php

namespace App\Jobs;

use App\Concerns\EmitsSerproEvents;
use App\Contracts\SerproEvents;
use App\Enums\SerproActionStatus;
use App\Models\SerproServiceRequest;
use App\Services\Monitoring\SerproActionExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Executes one explicit SERPRO fiscal Action through the `serpro` queue.
 *
 * The payload carries only the opaque action id: the executor re-reads the
 * action, so nothing sensitive travels through the queue and a stale payload
 * can never resurrect an Account boundary. The job never touches the
 * transport directly — {@see SerproActionExecutor} owns the fail-closed gate
 * and the state machine. While the action awaits its protocol or hit a
 * retryable outcome the job releases itself by the persisted readiness,
 * falling back to the documented backoff schedule.
 */
final class ExecuteSerproActionJob implements ShouldQueue
{
    use Dispatchable, EmitsSerproEvents, InteractsWithQueue, Queueable;

    public const ERROR_EXHAUSTED = 'queue_attempts_exhausted';

    public int $tries;

    public function __construct(public readonly int $actionId)
    {
        $this->tries = max(1, (int) config('monitoring.limits.max_attempts', 8));
        $this->onConnection((string) config('monitoring.queue_connection', 'serpro'));
        $this->onQueue((string) config('monitoring.queue', 'serpro'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values((array) config('monitoring.limits.retry_backoff', [15, 60, 300, 900]));
    }

    public function handle(SerproActionExecutor $executor): void
    {
        $action = $this->action();

        if ($action === null) {
            return;
        }

        $executed = $executor->execute($action);

        if (! $executed->status->isRetryable()) {
            return;
        }

        $this->release($this->delayFor($executed));
    }

    /**
     * The queue exhausted its attempts: mark the action with the factual
     * reason without throwing from the failure callback, and emit the terminal
     * `serpro_action_finished` so operators do not keep seeing the last
     * retryable event. Emission is best-effort and never breaks the callback.
     */
    public function failed(?Throwable $exception): void
    {
        $action = $this->action();

        if ($action === null || $action->status->isTerminal()) {
            return;
        }

        $action->forceFill([
            'status' => SerproActionStatus::Failed,
            'metadata' => [...(array) $action->metadata, 'error_code' => self::ERROR_EXHAUSTED],
        ])->save();

        $this->emitSerproEvent(
            'serpro_action_finished',
            fn () => app(SerproEvents::class)->actionFinished(
                (string) $action->id,
                (string) $action->account_id,
                (string) $action->operation_code,
                $action->status->value,
                $action->client_id === null ? null : (string) $action->client_id,
                self::ERROR_EXHAUSTED,
                null,
                false,
                true,
                now(),
            ),
        );
    }

    private function action(): ?SerproServiceRequest
    {
        return SerproServiceRequest::query()
            ->withoutGlobalScope('account')
            ->whereKey($this->actionId)
            ->first();
    }

    private function delayFor(SerproServiceRequest $action): int
    {
        $next = $action->metadata['next_attempt_at'] ?? null;

        if (is_string($next) && trim($next) !== '') {
            try {
                $at = Carbon::parse($next);

                if ($at->isFuture()) {
                    return max(1, (int) ceil(now()->diffInSeconds($at, false)));
                }
            } catch (Throwable) {
                // Fall through to the persisted retry_after/backoff.
            }
        }

        $retryAfter = $action->metadata['retry_after'] ?? null;

        if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
            return (int) $retryAfter;
        }

        return $this->fallbackRetryDelay();
    }

    private function fallbackRetryDelay(): int
    {
        $backoff = $this->backoff();
        $attempt = max(1, (int) $this->attempts());

        return max(1, $backoff[min($attempt - 1, count($backoff) - 1)]);
    }
}
