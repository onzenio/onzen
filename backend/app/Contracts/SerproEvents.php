<?php

namespace App\Contracts;

use App\Models\MonitoringRun;
use DateTimeInterface;

/**
 * Operational event sink for SERPRO execution (Task 19).
 *
 * The contract exists so callers depend on the emission role, not on a
 * specific sink; the production implementation logs and dispatches typed
 * events, while tests can bind a throwing sink to prove emission failures
 * never break a run. Implementations MUST be best-effort: never let a broken
 * listener or log channel propagate to the execution.
 */
interface SerproEvents
{
    public function runStarted(MonitoringRun $run): void;

    public function runFinished(MonitoringRun $run, ?string $reason = null): void;

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
    ): void;
}
