<?php

namespace App\Concerns;

use App\Support\Redactor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort emission of SERPRO operational events (Task 19).
 *
 * The event emitter is already best-effort internally, but this seam also
 * survives a broken emitter implementation: a throwing listener, log channel
 * or emitter is recorded as a technical log and never breaks the execution,
 * queue job or scheduler that emitted it.
 */
trait EmitsSerproEvents
{
    /**
     * @param  callable(): void  $emission
     */
    protected function emitSerproEvent(string $event, callable $emission): void
    {
        try {
            $emission();
        } catch (Throwable $exception) {
            try {
                Log::error('serpro_event_emission_failed', [
                    'event' => $event,
                    'error' => Redactor::text($exception->getMessage()),
                ]);
            } catch (Throwable) {
                // A broken log channel must never break the execution either.
            }
        }
    }
}
