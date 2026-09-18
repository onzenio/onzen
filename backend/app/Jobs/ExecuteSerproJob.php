<?php

namespace App\Jobs;

use App\Concerns\EmitsSerproEvents;
use App\Contracts\SerproEvents;
use App\Enums\MonitoringRunStatus;
use App\Exceptions\InvalidRunTransitionException;
use App\Models\MonitoringRun;
use App\Services\Monitoring\SerproExecutor;
use App\Services\Monitoring\SerproRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Executes one SERPRO Monitoring run through the `serpro` queue.
 *
 * The payload carries only the opaque run id: the executor re-reads the run,
 * so nothing sensitive travels through the queue and a stale payload can
 * never resurrect an Account boundary. The job never touches the transport
 * directly — {@see SerproExecutor} owns the fail-closed gate and the state
 * machine. While the run awaits its protocol or hit a retryable outcome the
 * job releases itself by the run `eta`/last attempt `retry_after`, falling
 * back to the documented backoff schedule.
 */
final class ExecuteSerproJob implements ShouldQueue
{
    use Dispatchable, EmitsSerproEvents, InteractsWithQueue, Queueable;

    public const ERROR_EXHAUSTED = 'queue_attempts_exhausted';

    public int $tries;

    public function __construct(public readonly int $runId)
    {
        $this->tries = max(1, (int) config('monitoring.limits.max_attempts', 8));
        $this->onConnection((string) config('monitoring.queue_connection', 'serpro'));
        $this->onQueue((string) config('monitoring.queue', 'serpro'));
    }

    /**
     * The fallback schedule for worker-managed retries and for runs without a
     * persisted readiness. Shared with the executor via `monitoring.limits`
     * so both release and duplicate-dispatch guards pace the same way.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values((array) config('monitoring.limits.retry_backoff', [15, 60, 300, 900]));
    }

    public function handle(SerproExecutor $executor, SerproRecovery $recovery): void
    {
        $run = $this->run();

        if ($run === null) {
            return;
        }

        $run = $recovery->recoverRun($run);

        $executed = $executor->execute($run);

        if (! in_array($executed->status, [
            MonitoringRunStatus::AwaitingProtocol,
            MonitoringRunStatus::Limited,
            MonitoringRunStatus::Transient,
        ], true)) {
            return;
        }

        $this->release($this->delayFor($executed));
    }

    /**
     * The queue exhausted its attempts: mark the run with the factual reason
     * without throwing from the failure callback, and emit the terminal
     * `serpro_run_finished` (Task 19) so operators do not keep seeing the last
     * retryable event. Emission is best-effort and never breaks the callback.
     */
    public function failed(?Throwable $exception): void
    {
        $run = $this->run();

        if ($run === null || $run->isTerminal()) {
            return;
        }

        try {
            if ($run->status->canTransitionTo(MonitoringRunStatus::Failed)) {
                $run = $run->transitionTo(MonitoringRunStatus::Failed, [
                    'error_code' => self::ERROR_EXHAUSTED,
                ]);
            } else {
                // `pending` has no edge to `failed`: the job threw before the
                // run started, so record the factual outcome directly.
                $run->forceFill([
                    'status' => MonitoringRunStatus::Failed,
                    'error_code' => self::ERROR_EXHAUSTED,
                    'finished_at' => now(),
                ])->save();
            }
        } catch (InvalidRunTransitionException) {
            // Another worker settled the run first; nothing factual to mark.
            return;
        }

        $this->emitSerproEvent(
            'serpro_run_finished',
            fn () => app(SerproEvents::class)->runFinished($run),
        );
    }

    private function run(): ?MonitoringRun
    {
        return MonitoringRun::query()
            ->withoutGlobalScope('account')
            ->whereKey($this->runId)
            ->first();
    }

    private function delayFor(MonitoringRun $run): int
    {
        $eta = $run->eta;

        if ($eta !== null && $eta->isFuture()) {
            return max(1, (int) ceil(now()->diffInSeconds($eta, false)));
        }

        $retryAfter = $run->attempts()->orderByDesc('attempt')->value('retry_after');

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
