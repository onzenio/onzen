<?php

namespace App\Exceptions;

use App\Enums\MonitoringRunStatus;
use DomainException;

/**
 * A Monitoring execution was asked to move through a transition that the
 * state machine does not allow. The message is factual and carries the exact
 * `from->to` pair, never payload contents.
 */
final class InvalidRunTransitionException extends DomainException
{
    public static function from(MonitoringRunStatus $from, MonitoringRunStatus $to): self
    {
        return new self("invalid_run_transition:{$from->value}->{$to->value}");
    }
}
