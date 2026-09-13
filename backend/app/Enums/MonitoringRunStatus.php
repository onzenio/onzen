<?php

namespace App\Enums;

/**
 * State machine of a Monitoring execution.
 *
 * Terminal states keep the result immutable; retryable states (`limited`,
 * `transient`) and `awaiting_protocol` are the seams Tasks 15/16 wire the
 * queue release, backoff and protocol polling into. The factual outcome of a
 * fenced/superseded execution is `discarded` (never projected).
 */
enum MonitoringRunStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case AwaitingProtocol = 'awaiting_protocol';

    case Completed = 'completed';

    case Limited = 'limited';

    case Transient = 'transient';

    case Rejected = 'rejected';

    case Expired = 'expired';

    case Failed = 'failed';

    case Blocked = 'blocked';

    case Discarded = 'discarded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Rejected,
            self::Expired,
            self::Failed,
            self::Blocked,
            self::Discarded => true,
            self::Pending,
            self::Running,
            self::AwaitingProtocol,
            self::Limited,
            self::Transient => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Blocked, self::Discarded],
            self::Running => [
                self::Completed, self::AwaitingProtocol, self::Limited, self::Transient,
                self::Rejected, self::Expired, self::Failed, self::Blocked, self::Discarded,
            ],
            self::AwaitingProtocol => [
                self::Running, self::Completed, self::Limited, self::Transient,
                self::Rejected, self::Expired, self::Failed, self::Blocked, self::Discarded,
            ],
            self::Limited,
            self::Transient => [self::Running, self::Failed, self::Blocked, self::Discarded],
            self::Completed,
            self::Rejected,
            self::Expired,
            self::Failed,
            self::Blocked,
            self::Discarded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
