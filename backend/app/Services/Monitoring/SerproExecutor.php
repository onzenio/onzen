<?php

namespace App\Services\Monitoring;

use App\Contracts\ResultProjector;
use App\Contracts\SerproEvents;
use App\Contracts\SerproTransport;
use App\Enums\MonitoringRunStatus;
use App\Exceptions\SerproBlockedException;
use App\Exceptions\SupersededRunException;
use App\Integrations\Serpro\ConsultFixtureProvider;
use App\Integrations\Serpro\ConsultMessageClassifier;
use App\Integrations\Serpro\ConsultOperationResolver;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Integrations\Serpro\ProtocolPoller;
use App\Integrations\Serpro\ResponseClassifier;
use App\Integrations\Serpro\SerproClassification;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Integrations\Serpro\SerproEnvelope;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringAttempt;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Support\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Motor de execução do monitoramento (Tasks 14/16).
 *
 * Owns the run lifecycle: idempotent claim by `(account, idempotency_key)`,
 * the state machine, fencing against `MonitoringEnrollment.version`, dry-run
 * fixture execution and the injected {@see ResultProjector} seam.
 *
 * Task 16 wires the response classification: 429/timeout/5xx become
 * `limited`/`transient` with backoff (never advancing the snapshot),
 * definitive rejections end as `rejected` without retry and a pending
 * protocol is polled by {@see ProtocolPoller} without repeating the original
 * request. A run in a retryable state refuses to send traffic before its
 * persisted `eta`/`retry_after`.
 *
 * Task 17 adds the Plan quota gate: {@see QueryQuotaService} reserves one
 * unit after the fencing check and before any transport work (dry-run
 * included). An exhausted Account ends the run factually as `blocked` with
 * `quota_exceeded` instead of throwing from the queue.
 *
 * Task 19 adds the operational events: `serpro_run_started` is emitted once
 * the run actually begins execution (after fencing and quota) and
 * `serpro_run_finished` exactly once per terminal/retryable outcome, with
 * redacted reasons. Emission is best-effort and never breaks the run.
 *
 * Fail-closed: a call is only attempted when the effective gate is open, and
 * missing credentials, an inactive enrollment or a missing fixture end the
 * run with a factual code. Nothing sensitive is logged or persisted.
 */
final class SerproExecutor
{
    private const SOURCE_FIXTURE = 'fixture';

    private const SOURCE_SERPRO = 'serpro';

    public function __construct(
        private readonly SerproTransportGate $gate,
        private readonly ConsultOperationResolver $operations,
        private readonly ConsultFixtureProvider $fixtures,
        private readonly SerproCredentialResolver $credentials,
        private readonly OAuthTokenCache $tokens,
        private readonly SerproTransport $transport,
        private readonly ResultProjector $projector,
        private readonly ResponseClassifier $responseClassifier,
        private readonly ConsultMessageClassifier $messages,
        private readonly ProtocolPoller $poller,
        private readonly QueryQuotaService $quota,
        private readonly SerproEvents $events,
    ) {}

    /**
     * Create or reuse the run for an idempotency key.
     *
     * Repeating a key returns the existing run untouched — no second run and
     * no external traffic. Reusing a key for a different enrollment is a
     * factual conflict instead of a silent mismatch.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws SerproBlockedException
     */
    public function claim(
        MonitoringEnrollment $enrollment,
        string $idempotencyKey,
        string $trigger = MonitoringRun::TRIGGER_MANUAL,
        array $parameters = [],
    ): MonitoringRun {
        $key = trim($idempotencyKey);
        if ($key === '') {
            throw new SerproBlockedException('idempotency_key_missing');
        }

        $accountId = (int) $enrollment->account_id;

        $existing = $this->findRun($accountId, $key);
        if ($existing !== null) {
            $this->assertSameEnrollment($existing, $enrollment);

            return $existing;
        }

        if (! $enrollment->isActive()) {
            throw new SerproBlockedException('enrollment_inactive');
        }

        $definition = $enrollment->definition()->first();
        if ($definition === null) {
            throw new SerproBlockedException('consult_definition_missing');
        }

        try {
            $operation = $this->operations->resolve($definition);
        } catch (\DomainException|InvalidArgumentException $exception) {
            throw new SerproBlockedException('consult_operation_unresolved', 0, $exception);
        }

        try {
            return MonitoringRun::query()->create([
                'account_id' => $accountId,
                'enrollment_id' => $enrollment->id,
                'trigger' => $trigger,
                'definition_key' => (string) $definition->getKey(),
                'operation_code' => $operation,
                'idempotency_key' => $key,
                'fencing_token' => (int) $enrollment->version,
                'status' => MonitoringRunStatus::Pending,
                'environment' => $this->gate->environment(),
                'dry_run' => $this->gate->isGated(),
                'parameters' => $parameters === [] ? ($enrollment->configuration ?? null) : $parameters,
                'external_code' => $operation,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Two concurrent claims: the unique key wins and the loser sees
            // the winner's run, exactly like a sequential repeat.
            $existing = $this->findRun($accountId, $key);
            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameEnrollment($existing, $enrollment);

            return $existing;
        }
    }

    /**
     * Advance a claimed run through the state machine.
     *
     * Idempotent by construction: terminal runs and runs already in flight
     * return untouched, runs in a retryable state refuse to send traffic
     * before their persisted `eta`/`retry_after`, and `awaiting_protocol`
     * polls the persisted protocol instead of repeating the original request.
     *
     * @throws SerproBlockedException
     */
    public function execute(MonitoringRun $run): MonitoringRun
    {
        $run->refresh();

        if ($run->status->isTerminal() || $run->status === MonitoringRunStatus::Running) {
            return $run;
        }

        // Carry-over from Task 15 review: a duplicate dispatch must not
        // bypass backoff. A future eta/retry_after refuses without traffic.
        if ($this->notYetDue($run)) {
            return $run;
        }

        // A persisted protocol means the original request was already
        // accepted: every retry from now on is a poll, never the original
        // consult again — even after a retryable poll body.
        $protocol = trim((string) $run->protocol);
        $polling = $protocol !== '';

        if ($run->status === MonitoringRunStatus::AwaitingProtocol && ! $polling) {
            return $this->finish($run, MonitoringRunStatus::Blocked, 'protocol_missing');
        }

        if (! $this->fences($run, $this->enrollmentFor($run))) {
            return $this->discard($run);
        }

        // Quota (Task 17): the Plan unit is reserved after the enrollment
        // fencing (a superseded run never spends volume) and before any
        // credential/token/fixture/transport work. Dry-runs reserve too, so
        // an exhausted Account is blocked even with the transport off. The
        // service is idempotent by run id, so retries and protocol polls
        // replay the reservation instead of charging again.
        $account = Account::query()->whereKey($run->account_id)->first();

        if ($account === null) {
            return $this->finish($run, MonitoringRunStatus::Blocked, 'account_missing');
        }

        try {
            $this->quota->reserve($account, $run);
        } catch (ValidationException) {
            return $this->finish($run, MonitoringRunStatus::Blocked, QueryQuotaService::ERROR_EXCEEDED);
        } catch (SerproBlockedException $exception) {
            return $this->finish($run, MonitoringRunStatus::Blocked, $exception->getMessage());
        }

        $dryRun = $this->gate->isGated();

        $run = $run->transitionTo(MonitoringRunStatus::Running, [
            'dry_run' => $dryRun,
            'error_code' => null,
        ]);

        // Task 19: `serpro_run_started` is emitted here — after fencing and
        // quota, right before any fixture/transport work — and every outcome
        // reaches the single finish/discard seam, which emits exactly one
        // `serpro_run_finished`. Emission is best-effort and never breaks the
        // run.
        $this->emit('serpro_run_started', fn () => $this->events->runStarted($run));

        try {
            if (trim((string) $run->operation_code) === '') {
                throw new SerproBlockedException('consult_operation_unresolved');
            }

            $result = $dryRun
                ? $this->fixtureResult($run, $polling)
                : $this->transportResult($run, $polling, $protocol);
        } catch (SerproBlockedException $exception) {
            return $this->finish($run, MonitoringRunStatus::Blocked, $exception->getMessage());
        } catch (Throwable $exception) {
            $classification = $this->responseClassifier->classifyException($exception);

            return $this->finish(
                $run,
                MonitoringRunStatus::Transient,
                $classification->code,
                retryAfter: $classification->retryAfter,
                reason: $exception->getMessage(),
            );
        }

        return $this->settle($run, $result);
    }

    /**
     * A run must not send traffic before its persisted readiness time: the
     * `eta` of a pending protocol, or the latest attempt `retry_after` of a
     * `limited`/`transient` outcome. The queue job re-releases by the same
     * values; this guard closes the duplicate-dispatch hole.
     */
    private function notYetDue(MonitoringRun $run): bool
    {
        $eta = $run->eta;

        if ($eta !== null && $eta->isFuture()) {
            return true;
        }

        if (! in_array($run->status, [MonitoringRunStatus::Limited, MonitoringRunStatus::Transient], true)) {
            return false;
        }

        $attempt = $run->attempts()->orderByDesc('attempt')->first();

        if ($attempt === null || $attempt->retry_after === null || $attempt->created_at === null) {
            return false;
        }

        return $attempt->created_at->copy()->addSeconds((int) $attempt->retry_after)->isFuture();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRun(int $accountId, string $idempotencyKey): ?MonitoringRun
    {
        return MonitoringRun::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function assertSameEnrollment(MonitoringRun $run, MonitoringEnrollment $enrollment): void
    {
        if ((int) $run->enrollment_id !== (int) $enrollment->getKey()) {
            throw new SerproBlockedException('idempotency_key_conflict');
        }
    }

    private function enrollmentFor(MonitoringRun $run): ?MonitoringEnrollment
    {
        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->whereKey($run->enrollment_id)
            ->first();
    }

    /**
     * The enrollment read under a row lock, used inside the completion
     * transaction so a concurrent version bump serializes against it.
     */
    private function lockedEnrollment(MonitoringRun $run): ?MonitoringEnrollment
    {
        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->whereKey($run->enrollment_id)
            ->lockForUpdate()
            ->first();
    }

    private function fences(MonitoringRun $run, ?MonitoringEnrollment $enrollment): bool
    {
        return $enrollment !== null
            && $enrollment->isActive()
            && (int) $enrollment->version === (int) $run->fencing_token;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureResult(MonitoringRun $run, bool $polling = false): array
    {
        $fixture = $this->fixtures->load((string) $run->operation_code, $polling);

        if ($fixture === null) {
            // Never invent a result for a dry-run without a fixture.
            throw new SerproBlockedException('fixture_missing');
        }

        return $this->normalize($run, $fixture, self::SOURCE_FIXTURE);
    }

    /**
     * @return array<string, mixed>
     */
    private function transportResult(MonitoringRun $run, bool $polling = false, string $protocol = ''): array
    {
        if ($this->gate->isGated()) {
            throw new SerproBlockedException('serpro_gated');
        }

        $environment = (string) $run->environment;
        $enrollment = $this->enrollmentFor($run);
        $client = $enrollment === null ? null : Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->whereKey($enrollment->client_id)
            ->first();
        if ($client === null) {
            throw new SerproBlockedException('client_missing');
        }

        // Fail-closed eligibility before any credential or token work: an
        // existing author whose certificate is expired (or whose status is
        // not active) must block with zero network traffic.
        $author = $this->eligibleAuthor((int) $run->account_id);

        $contract = SerproContract::query()->where('environment', $environment)->first();
        $credentials = $this->credentials->resolve($contract);
        $credentialRef = (string) ($contract?->credential_ref ?? '');

        $oauth = $this->tokens->get($credentials, $environment, $credentialRef);

        // Polling repeats the operation path with the protocol payload; the
        // original parameters are never resent.
        $parameters = $polling
            ? ['protocol' => $protocol, 'poll' => true]
            : (is_array($run->parameters) ? $run->parameters : []);

        $envelope = SerproEnvelope::make(
            SerproEnvelope::partyFor($credentials->contratanteDoc),
            SerproEnvelope::partyFor($author->document, $author->document_type->value),
            SerproEnvelope::partyFor($client->cnpj, 'PJ'),
            (string) $run->operation_code,
            $parameters,
        );

        $options = array_filter([
            'idempotency_key' => (string) $run->idempotency_key,
            'jwt_token' => $oauth['jwt_token'],
            'environment' => $environment,
        ], fn (mixed $value): bool => $value !== null);

        $response = $polling
            ? $this->poller->poll($run, $protocol, $envelope, $oauth['access_token'], $options)
            : $this->transport->call(
                ProcurationCatalog::pathFor((string) $run->operation_code),
                $envelope,
                $oauth['access_token'],
                $options,
            );

        $status = (int) ($response['status'] ?? 0);
        if ($this->tokens->forgetIfUnauthorized($status, $environment, $credentialRef)) {
            throw new SerproBlockedException('serpro_oauth_unauthorized');
        }

        return $this->normalize($run, $response, self::SOURCE_SERPRO);
    }

    /**
     * The newest eligible Author of the Account.
     *
     * Fail-closed and factual: no author at all is `author_pending`; authors
     * that exist but lack an active certificate (or are marked ineligible)
     * are `author_ineligible`. Both refuse before any credential/token work.
     *
     * @throws SerproBlockedException
     */
    private function eligibleAuthor(int $accountId): SerproRequestAuthor
    {
        $authors = SerproRequestAuthor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($authors->isEmpty()) {
            throw new SerproBlockedException('author_pending');
        }

        foreach ($authors as $author) {
            if ($author->isEligible()) {
                return $author;
            }
        }

        throw new SerproBlockedException('author_ineligible');
    }

    /**
     * Normalize a fixture envelope or a transport response into the internal
     * execution result. No classification happens here.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(MonitoringRun $run, array $raw, string $source): array
    {
        $body = is_array($raw['body'] ?? null) ? $raw['body'] : [];
        $eta = $this->etaFrom($body);

        return [
            'source' => $source,
            'operation_code' => (string) $run->operation_code,
            'http_status' => (int) ($raw['http_status'] ?? $raw['status'] ?? 0),
            'protocol' => $this->protocolFrom($body),
            'eta' => $eta?->toIso8601String(),
            'body' => $body,
            'headers' => is_array($raw['headers'] ?? null) ? $raw['headers'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function settle(MonitoringRun $run, array $result): MonitoringRun
    {
        $classification = $this->classify($result);
        $status = $this->statusFor($classification);
        $responseCode = (int) $result['http_status'];

        // The protocol already persisted on the run survives every
        // settlement: a retryable poll body (429/5xx) or a completion body
        // without `protocol`/`protocolo` must not orphan the polling flow.
        $protocol = $this->protocolFor($run, $result);

        if ($status === MonitoringRunStatus::Completed) {
            try {
                DB::transaction(function () use ($run, $result, $classification, $responseCode, $protocol): void {
                    // The fencing token is compared on the locked enrollment
                    // INSIDE the completion transaction, before the projector
                    // and again after it. The row lock makes a concurrent
                    // version bump serialize against the commit; the second
                    // check catches a bump that landed while projecting and
                    // rolls the projection back instead of committing it.
                    if (! $this->fences($run, $this->lockedEnrollment($run))) {
                        throw new SupersededRunException;
                    }

                    $this->projector->project($run, [
                        'source' => $result['source'],
                        'operation_code' => $result['operation_code'],
                        'http_status' => $result['http_status'],
                        'protocol' => $protocol,
                        'eta' => $result['eta'],
                        'body' => $result['body'],
                    ]);

                    if (! $this->fences($run, $this->lockedEnrollment($run))) {
                        throw new SupersededRunException;
                    }

                    $run->transitionTo(MonitoringRunStatus::Completed, [
                        'protocol' => $protocol,
                        'external_code' => $result['operation_code'],
                        'error_code' => null,
                    ]);

                    // Attempt record shares the completion transaction, so a
                    // rolled-back completion never leaves a phantom attempt.
                    $this->recordAttempt($run, MonitoringRunStatus::Completed, $responseCode, $classification->code);
                });
            } catch (SupersededRunException) {
                return $this->discard($run);
            } catch (Throwable) {
                return $this->finish($run, MonitoringRunStatus::Failed, 'projection_failed', $responseCode);
            }

            $run = $run->refresh();
            $this->emitFinished($run);

            return $run;
        }

        if (! $this->fences($run, $this->enrollmentFor($run))) {
            return $this->discard($run);
        }

        $run = $run->transitionTo($status, [
            'protocol' => $protocol,
            'eta' => $status === MonitoringRunStatus::AwaitingProtocol ? $result['eta'] : null,
            'external_code' => $result['operation_code'],
            'error_code' => $classification->code,
        ]);

        $this->recordAttempt($run, $status, $responseCode, $classification->code, $classification->retryAfter);

        $this->emitFinished($run);

        return $run;
    }

    /**
     * Keep the run protocol when the current result does not provide a valid
     * new one. Never turns a known protocol into null.
     *
     * @param  array<string, mixed>  $result
     */
    private function protocolFor(MonitoringRun $run, array $result): ?string
    {
        $incoming = is_scalar($result['protocol'] ?? null) ? trim((string) $result['protocol']) : '';

        return $incoming !== '' ? $incoming : $run->protocol;
    }

    /**
     * HTTP classification refined by the PGDAS-D business message code.
     *
     * @param  array<string, mixed>  $result
     */
    private function classify(array $result): SerproClassification
    {
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];

        return $this->messages->classify(
            $body,
            $this->responseClassifier->classify((int) $result['http_status'], $body, $headers),
        );
    }

    /**
     * Classification class → state machine status. Unknown classes are
     * refused instead of silently completing the run.
     */
    private function statusFor(SerproClassification $classification): MonitoringRunStatus
    {
        return match ($classification->status) {
            SerproClassification::SUCCESS => MonitoringRunStatus::Completed,
            SerproClassification::AWAITING_PROTOCOL => MonitoringRunStatus::AwaitingProtocol,
            SerproClassification::EXPIRED => MonitoringRunStatus::Expired,
            SerproClassification::RATE_LIMITED => MonitoringRunStatus::Limited,
            SerproClassification::TRANSIENT => MonitoringRunStatus::Transient,
            SerproClassification::REJECTED => MonitoringRunStatus::Rejected,
            default => throw new InvalidArgumentException('unsupported_classification'),
        };
    }

    private function discard(MonitoringRun $run): MonitoringRun
    {
        $run = $run->transitionTo(MonitoringRunStatus::Discarded, [
            'error_code' => MonitoringRun::DISCARDS_SUPERSEDED,
        ]);

        $this->recordAttempt($run, MonitoringRunStatus::Discarded, null, MonitoringRun::DISCARDS_SUPERSEDED);

        $this->emitFinished($run);

        return $run;
    }

    private function finish(
        MonitoringRun $run,
        MonitoringRunStatus $status,
        string $errorCode,
        ?int $responseCode = null,
        ?int $retryAfter = null,
        ?string $reason = null,
    ): MonitoringRun {
        $run = $run->transitionTo($status, ['error_code' => $errorCode]);

        $this->recordAttempt($run, $status, $responseCode, $errorCode, $retryAfter);

        $this->emitFinished($run, $reason);

        return $run;
    }

    /**
     * Task 19: the single finish seam — every terminal/retryable outcome
     * reaches it exactly once, with the factual `error_code` already persisted
     * and an optional free-form reason that the emitter redacts.
     */
    private function emitFinished(MonitoringRun $run, ?string $reason = null): void
    {
        $this->emit('serpro_run_finished', fn () => $this->events->runFinished($run, $reason));
    }

    /**
     * Emission is best-effort: a throwing listener, log channel or emitter
     * implementation is recorded as a technical log and never breaks the run.
     *
     * @param  callable(): void  $emission
     */
    private function emit(string $event, callable $emission): void
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

    private function recordAttempt(
        MonitoringRun $run,
        MonitoringRunStatus $status,
        ?int $responseCode,
        ?string $classification,
        ?int $retryAfter = null,
    ): MonitoringAttempt {
        $attempt = ((int) $run->attempts()->max('attempt')) + 1;

        return $run->attempts()->create([
            'attempt' => $attempt,
            'status' => $status,
            'response_code' => $responseCode,
            'classification' => $classification,
            // A retryable outcome always persists a readiness: the server
            // Retry-After when present, otherwise the backoff schedule for
            // this attempt. Without it a duplicate dispatch would bypass
            // backoff (notYetDue has nothing to compare against).
            'retry_after' => $retryAfter ?? $this->defaultRetryAfter($status, $attempt),
        ]);
    }

    /**
     * Backoff schedule for a `limited`/`transient` attempt without a server
     * `Retry-After`, indexed by the run attempt number (last value clamps).
     * The queue job reads the persisted value for its release.
     */
    private function defaultRetryAfter(MonitoringRunStatus $status, int $attempt): ?int
    {
        if (! in_array($status, [MonitoringRunStatus::Limited, MonitoringRunStatus::Transient], true)) {
            return null;
        }

        /** @var list<int|numeric-string> $backoff */
        $backoff = array_values((array) config('monitoring.limits.retry_backoff', [15, 60, 300, 900]));

        if ($backoff === []) {
            return 60;
        }

        return max(1, (int) $backoff[min(max($attempt, 1) - 1, count($backoff) - 1)]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function protocolFrom(array $body): ?string
    {
        $candidates = [
            $body['protocolo'] ?? null,
            $body['protocol'] ?? null,
            data_get($body, 'protocol.protocol_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function etaFrom(array $body): ?Carbon
    {
        $raw = $body['eta'] ?? data_get($body, 'protocol.eta');

        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }
}
