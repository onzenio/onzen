<?php

namespace App\Events\Monitoring;

/**
 * `serpro_action_finished` operational event.
 *
 * API ready for the fiscal actions of Task 27; like the run events it carries
 * only identifiers, factual status/reason codes and a redacted reason.
 */
final class SerproActionFinished
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public readonly array $context) {}
}
