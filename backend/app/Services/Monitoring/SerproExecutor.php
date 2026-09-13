<?php

namespace App\Services\Monitoring;

use App\Contracts\ResultProjector;
use App\Contracts\SerproTransport;
use App\Enums\AuthorStatus;
use App\Enums\MonitoringRunStatus;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\ConsultFixtureProvider;
use App\Integrations\Serpro\ConsultOperationResolver;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Integrations\Serpro\SerproEnvelope;
use App\Models\Client;
use App\Models\MonitoringAttempt;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Motor de execução do monitoramento (Task 14).
 *
 * Owns the run lifecycle: idempotent claim by `(account, idempotency_key)`,
 * the state machine, fencing against `MonitoringEnrollment.version`, dry-run
 * fixture execution and the injected {@see ResultProjector} seam.
 *
 * Explicitly out of scope here (Tasks 15/16/17): queue jobs and backoff,
 * response/message classification and protocol polling, quota reservation.
 * The states and transitions they consume are already in
 * {@see MonitoringRunStatus}; the minimal HTTP status mapping in
 * {@see self::classify()} is the seam Task 16 replaces.
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
     * return untouched, and `awaiting_protocol` waits for Task 16 polling
     * instead of repeating the original request.
     *
     * @throws SerproBlockedException
     */
    public function execute(MonitoringRun $run): MonitoringRun
    {
        $run->refresh();

        if ($run->status->isTerminal() || $run->status === MonitoringRunStatus::Running) {
            return $run;
        }

        if ($run->status === MonitoringRunStatus::AwaitingProtocol) {
            // Polling the persisted protocol is Task 16; re-running the
            // original request here would violate the spec.
            return $run;
        }

        if (! $this->fences($run, $this->enrollmentFor($run))) {
            return $this->discard($run);
        }

        $dryRun = $this->gate->isGated();

        $run = $run->transitionTo(MonitoringRunStatus::Running, [
            'dry_run' => $dryRun,
            'error_code' => null,
        ]);

        try {
            if (trim((string) $run->operation_code) === '') {
                throw new SerproBlockedException('consult_operation_unresolved');
            }

            $result = $dryRun
                ? $this->fixtureResult($run)
                : $this->transportResult($run);
        } catch (SerproBlockedException $exception) {
            return $this->finish($run, MonitoringRunStatus::Blocked, $exception->getMessage());
        } catch (Throwable) {
            return $this->finish($run, MonitoringRunStatus::Transient, 'transport_error');
        }

        // The external work is done: re-check the fencing token before any
        // result leaves the executor. A version bump during the call
        // supersedes this run and its result is discarded.
        if (! $this->fences($run, $this->enrollmentFor($run))) {
            return $this->discard($run);
        }

        return $this->settle($run, $result);
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

    private function fences(MonitoringRun $run, ?MonitoringEnrollment $enrollment): bool
    {
        return $enrollment !== null
            && $enrollment->isActive()
            && (int) $enrollment->version === (int) $run->fencing_token;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureResult(MonitoringRun $run): array
    {
        $fixture = $this->fixtures->load((string) $run->operation_code);

        if ($fixture === null) {
            // Never invent a result for a dry-run without a fixture.
            throw new SerproBlockedException('fixture_missing');
        }

        return $this->normalize($run, $fixture, self::SOURCE_FIXTURE);
    }

    /**
     * @return array<string, mixed>
     */
    private function transportResult(MonitoringRun $run): array
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

        $contract = SerproContract::query()->where('environment', $environment)->first();
        $credentials = $this->credentials->resolve($contract);
        $credentialRef = (string) ($contract?->credential_ref ?? '');

        $oauth = $this->tokens->get($credentials, $environment, $credentialRef);

        $author = $this->eligibleAuthor((int) $run->account_id);
        if ($author === null) {
            throw new SerproBlockedException('author_pending');
        }

        $envelope = SerproEnvelope::make(
            SerproEnvelope::partyFor($credentials->contratanteDoc),
            SerproEnvelope::partyFor($author->document, $author->document_type->value),
            SerproEnvelope::partyFor($client->cnpj, 'PJ'),
            (string) $run->operation_code,
            is_array($run->parameters) ? $run->parameters : [],
        );

        $response = $this->transport->call(
            ProcurationCatalog::pathFor((string) $run->operation_code),
            $envelope,
            $oauth['access_token'],
            array_filter([
                'idempotency_key' => (string) $run->idempotency_key,
                'jwt_token' => $oauth['jwt_token'],
                'environment' => $environment,
            ], fn (mixed $value): bool => $value !== null),
        );

        $status = (int) ($response['status'] ?? 0);
        if ($this->tokens->forgetIfUnauthorized($status, $environment, $credentialRef)) {
            throw new SerproBlockedException('serpro_oauth_unauthorized');
        }

        return $this->normalize($run, $response, self::SOURCE_SERPRO);
    }

    private function eligibleAuthor(int $accountId): ?SerproRequestAuthor
    {
        return SerproRequestAuthor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('status', AuthorStatus::Active)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
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
     * Minimal HTTP outcome mapping; Task 16 replaces it with the full
     * response/message classification without touching the state machine.
     *
     * @param  array<string, mixed>  $result
     * @return array{status: MonitoringRunStatus, classification: string, retry_after: int|null}
     */
    private function classify(array $result): array
    {
        $status = (int) $result['http_status'];
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $obtained = (bool) ($body['obtained'] ?? data_get($body, 'protocol.obtained', false));

        if ($result['protocol'] !== null && ! $obtained) {
            return ['status' => MonitoringRunStatus::AwaitingProtocol, 'classification' => 'protocol_pending', 'retry_after' => null];
        }

        if (($body['expired'] ?? false) === true) {
            return ['status' => MonitoringRunStatus::Expired, 'classification' => 'protocol_expired', 'retry_after' => null];
        }

        if ($status >= 200 && $status < 300) {
            return ['status' => MonitoringRunStatus::Completed, 'classification' => 'ok', 'retry_after' => null];
        }

        if ($status === 429) {
            return ['status' => MonitoringRunStatus::Limited, 'classification' => 'rate_limited', 'retry_after' => $this->retryAfter($result)];
        }

        if ($status === 0 || $status === 408 || $status >= 500) {
            return ['status' => MonitoringRunStatus::Transient, 'classification' => 'transient_error', 'retry_after' => $this->retryAfter($result)];
        }

        return ['status' => MonitoringRunStatus::Rejected, 'classification' => 'definitive_rejection', 'retry_after' => null];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function settle(MonitoringRun $run, array $result): MonitoringRun
    {
        $outcome = $this->classify($result);
        $status = $outcome['status'];
        $responseCode = (int) $result['http_status'];

        if ($status === MonitoringRunStatus::Completed) {
            try {
                DB::transaction(function () use ($run, $result): void {
                    // Projector is called only on a successful, non-superseded
                    // completion; a projection failure never leaves the run
                    // completed without the projected state.
                    $this->projector->project($run, [
                        'source' => $result['source'],
                        'operation_code' => $result['operation_code'],
                        'http_status' => $result['http_status'],
                        'protocol' => $result['protocol'],
                        'eta' => $result['eta'],
                        'body' => $result['body'],
                    ]);

                    $run->transitionTo(MonitoringRunStatus::Completed, [
                        'protocol' => $result['protocol'],
                        'external_code' => $result['operation_code'],
                        'error_code' => null,
                    ]);
                });
            } catch (Throwable) {
                return $this->finish($run, MonitoringRunStatus::Failed, 'projection_failed', $responseCode);
            }

            $this->recordAttempt($run, $status, $responseCode, $outcome['classification']);

            return $run->refresh();
        }

        $run = $run->transitionTo($status, [
            'protocol' => $result['protocol'],
            'eta' => $status === MonitoringRunStatus::AwaitingProtocol ? $result['eta'] : null,
            'external_code' => $result['operation_code'],
            'error_code' => $outcome['classification'],
        ]);

        $this->recordAttempt($run, $status, $responseCode, $outcome['classification'], $outcome['retry_after']);

        return $run;
    }

    private function discard(MonitoringRun $run): MonitoringRun
    {
        $run = $run->transitionTo(MonitoringRunStatus::Discarded, [
            'error_code' => MonitoringRun::DISCARDS_SUPERSEDED,
        ]);

        $this->recordAttempt($run, MonitoringRunStatus::Discarded, null, MonitoringRun::DISCARDS_SUPERSEDED);

        return $run;
    }

    private function finish(
        MonitoringRun $run,
        MonitoringRunStatus $status,
        string $errorCode,
        ?int $responseCode = null,
    ): MonitoringRun {
        $run = $run->transitionTo($status, ['error_code' => $errorCode]);

        $this->recordAttempt($run, $status, $responseCode, $errorCode);

        return $run;
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
            'retry_after' => $retryAfter,
        ]);
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function retryAfter(array $result): ?int
    {
        $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];
        $value = $headers['Retry-After'] ?? $headers['retry-after'] ?? data_get($result, 'body.retry_after');

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }
}
