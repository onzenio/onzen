<?php

namespace App\Enums;

/**
 * State machine of an explicit SERPRO fiscal Action (Task 27).
 *
 * Terminal states keep the result immutable. `pending` covers both a request
 * awaiting its first dispatch and a protocol awaiting poll (the persisted
 * `protocol` distinguishes them); `rate_limited` mirrors the 429 class. The
 * queue job releases retryable actions by their persisted readiness.
 */
enum SerproActionStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Succeeded = 'succeeded';

    case RateLimited = 'rate_limited';

    case Rejected = 'rejected';

    case Expired = 'expired';

    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Rejected, self::Expired, self::Failed => true,
            self::Pending, self::Running, self::RateLimited => false,
        };
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Pending, self::RateLimited => true,
            self::Running, self::Succeeded, self::Rejected, self::Expired, self::Failed => false,
        };
    }
}
