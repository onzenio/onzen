<?php

namespace App\Events\Monitoring;

/**
 * `serpro_run_finished` operational event.
 *
 * Emitted once per terminal or retryable outcome, with the factual status,
 * error code and a redacted reason — never the response body or credentials.
 */
final class SerproRunFinished
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public readonly array $context) {}
}
