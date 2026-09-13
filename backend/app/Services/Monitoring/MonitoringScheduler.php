<?php

namespace App\Services\Monitoring;

use App\Enums\MonitoringRunStatus;
use App\Exceptions\SerproBlockedException;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Scheduler de execuções (Task 18 / design decisions 6 e 7).
 *
 * Owns the decision of *when* a Monitoring run is requested: manual triggers
 * (single association or the Account's whole active portfolio) and the
 * automatic monthly cycle. The run itself is still claimed and executed by
 * {@see SerproExecutor}; the scheduler only gates eligibility and quota
 * before the job exists.
 *
 * Ordering is deliberate: the idempotency key is
 * `enrollment + fencing + trigger + minute`, so a double click inside the
 * same minute claims the same run and never duplicates traffic. Eligibility
 * (active association, active Client Monitoring Status, and — for the
 * automatic trigger — a definition marked automatic) is asserted before the
 * claim. Quota is reserved after the claim (it is idempotent by run id) and
 * before the dispatch; an exhausted Account leaves the run factually
 * `blocked/quota_exceeded` and rethrows the 422 upgrade message. The queue
 * dispatch is committed with `afterCommit`, and no external call ever
 * happens inside an HTTP request.
 */
final class MonitoringScheduler
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        private readonly SerproExecutor $executor,
        private readonly QueryQuotaService $quota,
    ) {}

    /**
     * Schedule (or replay) one run for an association.
     *
     * @throws ValidationException Quota exhausted (422 with upgrade message).
     * @throws SerproBlockedException Factual refusal: inactive association,
     *                                Monitoring Status off, definition not
     *                                eligible for the trigger, etc.
     */
    public function schedule(
        MonitoringEnrollment $enrollment,
        string $trigger = MonitoringRun::TRIGGER_MANUAL,
    ): MonitoringRun {
        $key = $this->idempotencyKey($enrollment, $trigger);

        // Replay first: the same minute must return the existing run even if
        // the association changed state after the original request.
        $existing = $this->findRun($enrollment, $key);
        if ($existing !== null) {
            return $this->replay($enrollment, $existing);
        }

        $reason = $this->ineligibility($enrollment, $trigger);
        if ($reason !== null) {
            throw new SerproBlockedException($reason);
        }

        $run = $this->executor->claim($enrollment, $key, $trigger);

        // A concurrent caller won the unique key: it owns the reservation
        // and the dispatch.
        if (! $run->wasRecentlyCreated) {
            return $this->replay($enrollment, $run);
        }

        $this->reserve($enrollment, $run);

        ExecuteSerproJob::dispatch($run->id)->afterCommit();

        return $run;
    }

    /**
     * Schedule the Account's active portfolio in a batch. Optional filters
     * narrow it by Client or definition.
     *
     * Callers get factual counts. Quota exhaustion interrupts the batch
     * cleanly — the exhausted enrollment is `blocked`, every enrollment left
     * unprocessed is `skipped` — and never turns into a 500.
     *
     * @param  array<string, mixed>  $filters
     * @return array{queued: int, blocked: int, skipped: int}
     */
    public function sync(User $actor, array $filters = []): array
    {
        $accountId = (int) (CurrentAccount::get() ?? $actor->account_id);
        $counts = ['queued' => 0, 'blocked' => 0, 'skipped' => 0];
        $stop = false;

        $query = MonitoringEnrollment::query()
            ->with(['client', 'definition'])
            ->where('account_id', $accountId)
            ->where('status', MonitoringEnrollment::STATUS_ACTIVE);

        $clientId = $filters['client_id'] ?? null;
        if (is_numeric($clientId) && (int) $clientId > 0) {
            $query->where('client_id', (int) $clientId);
        }

        $definitionId = trim((string) ($filters['definition_id'] ?? ''));
        if ($definitionId !== '') {
            $query->where('definition_id', $definitionId);
        }

        foreach ($query->lazyById(100) as $enrollment) {
            if ($stop) {
                $counts['skipped']++;

                continue;
            }

            try {
                $run = $this->schedule($enrollment, MonitoringRun::TRIGGER_MANUAL);
                $run->wasRecentlyCreated ? $counts['queued']++ : $counts['skipped']++;
            } catch (ValidationException) {
                // No volume left: every further reservation would fail the
                // same way, so the batch stops here instead of 500ing.
                $counts['blocked']++;
                $stop = true;
            } catch (SerproBlockedException) {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * Factual reason an association cannot run for the trigger, or null when
     * it can. Shared by the scheduler and by the monthly preview.
     */
    public function ineligibility(MonitoringEnrollment $enrollment, string $trigger): ?string
    {
        if (! in_array($trigger, [MonitoringRun::TRIGGER_MANUAL, MonitoringRun::TRIGGER_AUTOMATIC], true)) {
            return 'consult_trigger_invalid';
        }

        $enrollment->loadMissing(['client', 'definition']);

        if (! $enrollment->isActive()) {
            return 'enrollment_inactive';
        }

        $client = $enrollment->client;
        if ($client === null
            || (int) $client->account_id !== (int) $enrollment->account_id
            || ! $client->monitoring_enabled) {
            return 'monitoring_disabled';
        }

        $definition = $enrollment->definition;
        if ($definition === null || ! $definition->isAvailable()) {
            return 'definition_unavailable';
        }

        if ($trigger === MonitoringRun::TRIGGER_AUTOMATIC
            && $definition->strategy !== MonitoringDefinition::STRATEGY_AUTOMATIC) {
            return 'definition_not_automatic';
        }

        return null;
    }

    /**
     * A replay mirrors the factual state of the run it returns. An existing
     * run blocked by quota repeats the 422 upgrade message instead of a
     * misleading 202.
     */
    private function replay(MonitoringEnrollment $enrollment, MonitoringRun $run): MonitoringRun
    {
        if ($run->status === MonitoringRunStatus::Blocked
            && $run->error_code === QueryQuotaService::ERROR_EXCEEDED) {
            $account = Account::query()->whereKey($enrollment->account_id)->first();

            if ($account !== null) {
                $this->quota->assertWithinLimit($account);
            }
        }

        return $run;
    }

    /**
     * Reserve the Plan unit and, on refusal, leave the run blocked with the
     * factual cause before surfacing the error.
     *
     * @throws ValidationException
     * @throws SerproBlockedException
     */
    private function reserve(MonitoringEnrollment $enrollment, MonitoringRun $run): void
    {
        $account = Account::query()->whereKey($enrollment->account_id)->first();

        if ($account === null) {
            $this->block($run, 'account_missing');

            throw new SerproBlockedException('account_missing');
        }

        try {
            $this->quota->reserve($account, $run);
        } catch (ValidationException $exception) {
            $this->block($run, QueryQuotaService::ERROR_EXCEEDED);

            throw $exception;
        } catch (SerproBlockedException $exception) {
            $this->block($run, $exception->getMessage());

            throw $exception;
        }
    }

    private function block(MonitoringRun $run, string $reason): void
    {
        if ($run->status !== MonitoringRunStatus::Pending) {
            return;
        }

        $run->transitionTo(MonitoringRunStatus::Blocked, ['error_code' => $reason]);

        $run->attempts()->create([
            'attempt' => 1,
            'status' => MonitoringRunStatus::Blocked,
            'response_code' => null,
            'classification' => $reason,
            'retry_after' => null,
        ]);
    }

    private function findRun(MonitoringEnrollment $enrollment, string $key): ?MonitoringRun
    {
        return MonitoringRun::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $enrollment->account_id)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * `enrollment + fencing token + trigger + minute`, in the same timezone
     * the quota cycle uses.
     */
    private function idempotencyKey(MonitoringEnrollment $enrollment, string $trigger): string
    {
        $minute = Carbon::now(self::TIMEZONE)->format('Y-m-d-H-i');

        return implode(':', [
            'run',
            (string) $enrollment->getKey(),
            (string) $enrollment->version,
            $trigger,
            $minute,
        ]);
    }
}
