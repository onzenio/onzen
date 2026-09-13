<?php

namespace App\Services\Monitoring;

use App\Concerns\EmitsSerproEvents;
use App\Contracts\SerproEvents;
use App\Contracts\SerproTransport;
use App\Enums\SerproActionStatus;
use App\Exceptions\ArtifactStorageUnavailableException;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\ConsultArtifactStore;
use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\ConsultMessageClassifier;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Integrations\Serpro\ProtocolPoller;
use App\Integrations\Serpro\ResponseClassifier;
use App\Integrations\Serpro\SerproClassification;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Integrations\Serpro\SerproEnvelope;
use App\Jobs\ExecuteSerproActionJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Models\ParcelmentInstallment;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproServiceRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Executor das Ações Fiscais explícitas (Task 27 / design decisões 8, 10, 11).
 *
 * Emissão de DAS é sempre uma ação humana identificada: `request()` exige
 * confirmação explícita e chave de idempotência da interface, valida as
 * pré-condições fail-closed (transporte aprovado, credencial resolvível, autor
 * elegível, procuração aplicável, inscrição ativa) e persiste a intenção antes
 * de qualquer tráfego; repetir a chave devolve a ação existente. `execute()`
 * roda no worker e nunca reenvia uma emissão já protocolada: com protocolo
 * persistido ele apenas polla, pelo maquinário da Task 16.
 *
 * Escopo estrito: declaração, transmissão e qualquer emissão diferente de DAS
 * são recusadas explicitamente sem chamada externa. O sucesso guarda o PDF no
 * {@see ArtifactStore} (via {@see ConsultArtifactStore}) e, no parcelamento,
 * vincula `parcelment_installments.guide_ref`; falha de armazenamento ou de
 * decodificação fica registrada como falha de artefato sem inventar guia
 * baixável. Nada sensível é logado ou persistido: apenas ids opacos, códigos
 * factuais e metadados redigidos.
 */
final class SerproActionExecutor
{
    use EmitsSerproEvents;

    public const ERROR_CONFIRMATION_REQUIRED = 'explicit_confirmation_required';

    public const ERROR_KEY_MISSING = 'idempotency_key_missing';

    public const ERROR_KEY_CONFLICT = 'idempotency_key_conflict';

    public const ERROR_OUT_OF_SCOPE = 'fiscal_action_out_of_scope';

    public const ERROR_GATED = 'serpro_gated';

    public const ERROR_ENROLLMENT_REQUIRED = 'enrollment_required';

    public const ERROR_ENROLLMENT_INACTIVE = 'enrollment_inactive';

    public const ERROR_INSTALLMENT_REQUIRED = 'installment_required';

    public const ERROR_MODALITY_UNAVAILABLE = 'parcelment_modality_unavailable';

    public const ERROR_MODALITY_MISMATCH = 'modality_mismatch';

    public const ERROR_CLIENT_ACCOUNT_MISMATCH = 'client_account_mismatch';

    public const ERROR_ARTIFACT_STORAGE_UNAVAILABLE = 'artifact_storage_unavailable';

    public const ERROR_ARTIFACT_DECODE_FAILED = 'artifact_decode_failed';

    public const ERROR_ARTIFACT_MISSING = 'artifact_missing';

    public function __construct(
        private readonly SerproTransportGate $gate,
        private readonly SerproCredentialResolver $credentials,
        private readonly OAuthTokenCache $tokens,
        private readonly SerproTransport $transport,
        private readonly ProtocolPoller $poller,
        private readonly ResponseClassifier $responseClassifier,
        private readonly ConsultMessageClassifier $messages,
        private readonly ProcurationVerifier $procuration,
        private readonly ConsultArtifactStore $artifacts,
        private readonly SerproEvents $events,
        private readonly AuditService $audit,
    ) {}

    /**
     * Claim (or replay) an explicit DAS emission.
     *
     * Everything before the create is a factual refusal with zero external
     * calls and zero persisted actions. Repeating the key returns the existing
     * action untouched, even if the current preconditions would now refuse it
     * (a concluded request is a fact, not a new decision).
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws SerproBlockedException factual refusal, including
     *                                `idempotency_key_conflict`
     */
    public function request(
        Client $client,
        string $operationCode,
        string $idempotencyKey,
        bool $confirmed,
        array $parameters = [],
        ?MonitoringEnrollment $enrollment = null,
        ?ParcelmentInstallment $installment = null,
        ?User $actor = null,
    ): SerproServiceRequest {
        $operationCode = strtoupper(trim($operationCode));
        $accountId = (int) $client->account_id;
        $key = trim($idempotencyKey);

        if (! $confirmed) {
            $this->auditRequest($client, $operationCode, $enrollment, $installment, $actor, 'refused', self::ERROR_CONFIRMATION_REQUIRED);

            throw new SerproBlockedException(self::ERROR_CONFIRMATION_REQUIRED);
        }

        if ($key === '') {
            $this->auditRequest($client, $operationCode, $enrollment, $installment, $actor, 'refused', self::ERROR_KEY_MISSING);

            throw new SerproBlockedException(self::ERROR_KEY_MISSING);
        }

        // The canonical operation resolves the generic GERARDAS from the
        // modality before any replay comparison or persistence.
        $operationCode = $this->canonicalOperation($operationCode, $installment);

        // Replay first: a concluded request is a fact, not a new decision,
        // even if the current preconditions would now refuse it.
        $existing = $this->find($accountId, $key);

        if ($existing !== null) {
            $this->assertSameBinding($existing, $client, $operationCode, $parameters, $enrollment, $installment);

            return $existing;
        }

        $refusal = $this->refusal($operationCode, $client, $enrollment, $installment);

        if ($refusal !== null) {
            $this->auditRequest($client, $operationCode, $enrollment, $installment, $actor, 'refused', $refusal);

            throw new SerproBlockedException($refusal);
        }

        try {
            $action = SerproServiceRequest::query()->create([
                'account_id' => $accountId,
                'client_id' => (int) $client->getKey(),
                'enrollment_id' => $enrollment === null ? null : (int) $enrollment->getKey(),
                'installment_id' => $installment === null ? null : (int) $installment->getKey(),
                'operation_code' => $operationCode,
                'modality' => $installment === null ? null : ConsultCatalog::parcelmentModality($operationCode),
                'idempotency_key' => $key,
                'status' => SerproActionStatus::Pending,
                'parameters' => $parameters === [] ? null : $parameters,
                'metadata' => [
                    'environment' => $this->gate->environment(),
                    'requested_by_user_id' => $actor?->id,
                    'attempt' => 0,
                ],
                'requested_by_user_id' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Two concurrent claims: the unique key wins and the loser sees
            // the winner's action, exactly like a sequential repeat.
            $existing = $this->find($accountId, $key);

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameBinding($existing, $client, $operationCode, $parameters, $enrollment, $installment);

            return $existing;
        }

        $this->auditRequest($client, $operationCode, $enrollment, $installment, $actor, 'accepted', null, $action);

        ExecuteSerproActionJob::dispatch($action->id)->afterCommit();

        return $action;
    }

    /**
     * Advance a claimed action through the state machine.
     *
     * Idempotent by construction: terminal actions return untouched, an
     * action already in flight is left to its worker, a retryable action
     * refuses traffic before its persisted readiness, and a persisted
     * protocol means the original emission was already accepted — every retry
     * from then on is a poll, never a second GERARDAS.
     *
     * @throws SerproBlockedException
     */
    public function execute(SerproServiceRequest $action): SerproServiceRequest
    {
        $action->refresh();

        if ($action->status->isTerminal() || $action->status === SerproActionStatus::Running) {
            return $action;
        }

        if ($this->notYetDue($action)) {
            return $action;
        }

        $client = $this->clientFor($action);

        if ($client === null) {
            return $this->fail($action, 'client_missing');
        }

        $enrollment = $this->enrollmentFor($action);

        if ($action->enrollment_id !== null && ($enrollment === null || ! $enrollment->isActive())) {
            return $this->fail($action, self::ERROR_ENROLLMENT_INACTIVE);
        }

        $procuration = $this->missingProcuration($client, (string) $action->operation_code, $enrollment);

        if ($procuration !== null) {
            return $this->fail($action, $procuration);
        }

        if ($this->gate->isGated()) {
            return $this->fail($action, self::ERROR_GATED);
        }

        $environment = $this->environmentFor($action);
        $protocol = trim((string) $action->protocol);
        $polling = $protocol !== '';
        $metadata = (array) $action->metadata;
        $attempt = ((int) ($metadata['attempt'] ?? 0)) + 1;

        $action = $this->persist($action, SerproActionStatus::Running, [], [
            'attempt' => $attempt,
            'started_at' => now()->toIso8601String(),
            'error_code' => null,
        ]);

        try {
            $author = $this->eligibleAuthor((int) $action->account_id);
            $contract = SerproContract::query()->where('environment', $environment)->first();
            $credentials = $this->credentials->resolve($contract);
            $credentialRef = (string) ($contract?->credential_ref ?? '');
            $oauth = $this->tokens->get($credentials, $environment, $credentialRef);

            $envelope = SerproEnvelope::make(
                SerproEnvelope::partyFor($credentials->contratanteDoc),
                SerproEnvelope::partyFor($author->document, $author->document_type->value),
                SerproEnvelope::partyFor($client->cnpj, 'PJ'),
                (string) $action->operation_code,
                $polling ? ['protocol' => $protocol, 'poll' => true] : (is_array($action->parameters) ? $action->parameters : []),
            );

            $options = array_filter([
                'idempotency_key' => (string) $action->idempotency_key,
                'jwt_token' => $oauth['jwt_token'],
                'environment' => $environment,
            ], fn (mixed $value): bool => $value !== null);

            $response = $polling
                ? $this->poller->pollExplicit((string) $action->operation_code, (int) $action->account_id, $environment, $protocol, $envelope, $oauth['access_token'], $options)
                : $this->transport->call(ProcurationCatalog::pathFor((string) $action->operation_code), $envelope, $oauth['access_token'], $options);

            $status = (int) ($response['status'] ?? 0);

            if ($this->tokens->forgetIfUnauthorized($status, $environment, $credentialRef)) {
                return $this->retry($action, 'serpro_oauth_unauthorized');
            }
        } catch (SerproBlockedException $exception) {
            return $this->fail($action, $exception->getMessage());
        } catch (Throwable $exception) {
            $classification = $this->responseClassifier->classifyException($exception);

            return $this->settle($action, [
                'operation_code' => (string) $action->operation_code,
                'http_status' => 0,
                'body' => [],
                'headers' => [],
                'classification' => $classification,
                'protocol' => null,
            ]);
        }

        try {
            return $this->settle($action, $this->normalize((string) $action->operation_code, $response));
        } catch (Throwable $exception) {
            // A settlement failure after the SERPRO call (e.g. an unexpected
            // artifact driver error) must not leave the action in flight
            // forever: it ends factually failed without inventing a document.
            Log::error('monitoring.action_settlement_failed', [
                'request_id' => (string) $action->id,
                'account_id' => (int) $action->account_id,
                'operation_code' => (string) $action->operation_code,
                'error' => $exception->getMessage(),
            ]);

            return $this->fail($action, 'action_settlement_failed', SerproActionStatus::Failed);
        }
    }

    /**
     * Factual pre-dispatch refusal, or null when the request may be claimed.
     * Identity and key are validated by `request()` before the replay; this
     * seam covers scope and the fail-closed preconditions — all of them
     * local, none of them touching the wire.
     */
    private function refusal(
        string $operationCode,
        Client $client,
        ?MonitoringEnrollment $enrollment,
        ?ParcelmentInstallment $installment,
    ): ?string {
        $accountId = (int) $client->account_id;

        if ($enrollment !== null && (int) $enrollment->account_id !== $accountId) {
            return self::ERROR_CLIENT_ACCOUNT_MISMATCH;
        }

        if ($installment !== null && ((int) $installment->account_id !== $accountId || (int) $installment->client_id !== (int) $client->getKey())) {
            return self::ERROR_CLIENT_ACCOUNT_MISMATCH;
        }

        $scope = $this->scopeRefusal($operationCode, $installment);

        if ($scope !== null) {
            return $scope;
        }

        if ($operationCode === 'GERARDAS12' && $enrollment === null) {
            return self::ERROR_ENROLLMENT_REQUIRED;
        }

        if ($enrollment !== null && ! $enrollment->isActive()) {
            return self::ERROR_ENROLLMENT_INACTIVE;
        }

        if ($this->gate->isGated()) {
            return self::ERROR_GATED;
        }

        try {
            $contract = SerproContract::query()->where('environment', $this->gate->environment())->first();
            $this->credentials->resolve($contract);
        } catch (SerproBlockedException $exception) {
            return $exception->getMessage();
        }

        try {
            $this->eligibleAuthor($accountId);
        } catch (SerproBlockedException $exception) {
            return $exception->getMessage();
        }

        return $this->missingProcuration($client, $operationCode, $enrollment);
    }

    /**
     * Strict DAS scope: only GERARDAS12 (PGDAS-D) and the catalogued GERARDAS*
     * of a parcelment modality are executable; declarations, transmissions
     * and any other emission are rejected explicitly.
     */
    private function scopeRefusal(string $operationCode, ?ParcelmentInstallment $installment): ?string
    {
        if (str_starts_with($operationCode, 'TRANSDECLARACAO')
            || str_contains($operationCode, 'ENTREGAR')
            || $operationCode === 'ENVIOXMLASSINADO81') {
            return self::ERROR_OUT_OF_SCOPE;
        }

        if ($operationCode === 'GERARDAS12') {
            return null;
        }

        if (! str_starts_with($operationCode, 'GERARDAS')) {
            return self::ERROR_OUT_OF_SCOPE;
        }

        if ($installment === null) {
            return self::ERROR_INSTALLMENT_REQUIRED;
        }

        $modality = (string) ($installment->order?->modality ?? '');
        $expected = ConsultCatalog::gerardasForModality($modality);

        if ($expected === null) {
            return self::ERROR_MODALITY_UNAVAILABLE;
        }

        if ($operationCode !== 'GERARDAS' && $operationCode !== $expected) {
            return self::ERROR_MODALITY_MISMATCH;
        }

        return null;
    }

    /**
     * The concrete operation catalogued for the modality when the caller sent
     * the generic `GERARDAS` (the envelope cannot resolve the generic code).
     */
    private function canonicalOperation(string $operationCode, ?ParcelmentInstallment $installment): string
    {
        if ($operationCode !== 'GERARDAS' || $installment === null) {
            return $operationCode;
        }

        return ConsultCatalog::gerardasForModality((string) ($installment->order?->modality ?? '')) ?? $operationCode;
    }

    /**
     * Fail-closed procurement precondition against the local outorga registry.
     *
     * Port decision: the legacy DAS controller refused from the local
     * registry (`missingGroups`) instead of the live `OBTERPROCURACAO41`
     * verification the consult chain performs, so an emission never spends an
     * extra external call proving what the Account already knows. Absent and
     * expired grants stay distinguishable and factual.
     */
    private function missingProcuration(Client $client, string $operationCode, ?MonitoringEnrollment $enrollment): ?string
    {
        $definitionCodes = null;

        if ($enrollment !== null) {
            $definition = $enrollment->definition()->first();
            $definitionCodes = $definition?->procuration_codes;
        }

        $groups = ProcurationVerifier::requiredGroups(
            $definitionCodes,
            (string) ($enrollment?->definition_id ?? ''),
            $operationCode,
        );

        $missing = $this->procuration->missingGroups($client, $groups);

        if ($missing === []) {
            return null;
        }

        return $this->procuration->reasonForMissing($client, $missing);
    }

    /**
     * A key is bound to its Client, operation, resource (enrollment or
     * installment) and the non-secret request parameters that identify the
     * emission (period/consolidation): any mismatch is a factual conflict
     * instead of a silent second guide.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function assertSameBinding(
        SerproServiceRequest $action,
        Client $client,
        string $operationCode,
        array $parameters,
        ?MonitoringEnrollment $enrollment,
        ?ParcelmentInstallment $installment,
    ): void {
        if ((int) $action->client_id !== (int) $client->getKey()
            || (string) $action->operation_code !== $operationCode
            || (int) ($action->enrollment_id ?? 0) !== (int) ($enrollment?->getKey() ?? 0)
            || (int) ($action->installment_id ?? 0) !== (int) ($installment?->getKey() ?? 0)) {
            throw new SerproBlockedException(self::ERROR_KEY_CONFLICT);
        }

        $stored = (array) $action->parameters;

        foreach (['periodo_apuracao', 'data_consolidacao'] as $field) {
            if (array_key_exists($field, $parameters)
                && (string) ($stored[$field] ?? '') !== (string) $parameters[$field]) {
                throw new SerproBlockedException(self::ERROR_KEY_CONFLICT);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(string $operationCode, array $raw): array
    {
        $body = is_array($raw['body'] ?? null) ? $raw['body'] : [];
        $headers = is_array($raw['headers'] ?? null) ? $raw['headers'] : [];

        $classification = $this->messages->classify(
            $body,
            $this->responseClassifier->classify((int) ($raw['status'] ?? 0), $body, $headers),
        );

        $eta = $this->etaFrom($body);

        return [
            'operation_code' => $operationCode,
            'http_status' => (int) ($raw['status'] ?? 0),
            'body' => $body,
            'headers' => $headers,
            'classification' => $classification,
            'protocol' => $this->protocolFrom($body),
            'eta' => $eta?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function settle(SerproServiceRequest $action, array $result): SerproServiceRequest
    {
        $classification = $result['classification'];

        if (! $classification instanceof SerproClassification) {
            throw new InvalidArgumentException('unsupported_classification');
        }

        $status = $this->statusFor($classification);

        if ($status === SerproActionStatus::Succeeded) {
            return $this->succeed($action, $result, $classification);
        }

        $attempt = ((int) (($action->metadata['attempt'] ?? 0))) ?: 1;
        $retryAfter = $status->isRetryable() ? $this->retryDelay($classification, $result, $attempt) : null;
        $protocol = $this->protocolFor($action, $result);

        $action = $this->persist($action, $status, [
            'protocol' => $protocol,
            'document_ref' => null,
        ], [
            'classification' => $this->classificationMeta($classification),
            'error_code' => $classification->code,
            'retry_after' => $retryAfter,
            'next_attempt_at' => $retryAfter === null ? null : now()->addSeconds($retryAfter)->toIso8601String(),
            'response_code' => (int) $result['http_status'],
        ]);

        $this->emitFinished($action, $classification->code, $classification->retryable, $status->isTerminal());
        $this->auditFinished($action);

        return $action;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function succeed(SerproServiceRequest $action, array $result, SerproClassification $classification): SerproServiceRequest
    {
        $artifact = $this->storeArtifact($action, $result);
        $documentRef = is_string($artifact['ref'] ?? null) ? $artifact['ref'] : null;

        // The action and the parcelment guide commit together: the download
        // route can never see a guide_ref whose action was not persisted.
        $action = DB::transaction(function () use ($action, $result, $classification, $artifact, $documentRef): SerproServiceRequest {
            if ($documentRef !== null && $action->installment_id !== null) {
                $this->installmentFor($action)?->update(['guide_ref' => $documentRef]);
            }

            return $this->persist($action, SerproActionStatus::Succeeded, [
                'protocol' => $this->protocolFor($action, $result),
                'document_ref' => $documentRef,
            ], [
                'classification' => $this->classificationMeta($classification),
                'artifact' => $artifact,
                'error_code' => isset($artifact['reason']) ? $this->artifactErrorCode((string) $artifact['reason']) : null,
                'retry_after' => null,
                'next_attempt_at' => null,
                'response_code' => (int) $result['http_status'],
                'finished_at' => now()->toIso8601String(),
            ]);
        });

        $this->emitFinished(
            $action,
            isset($artifact['reason']) ? $this->artifactErrorCode((string) $artifact['reason']) : null,
            false,
            true,
        );
        $this->auditFinished($action);

        return $action;
    }

    /**
     * Store the emitted PDF/XML through the private artifact store.
     *
     * A storage or decode failure is recorded factually and never invents a
     * downloadable document: `document_ref` stays null and the action metadata
     * carries `artifact.status = failed` with the reason.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function storeArtifact(SerproServiceRequest $action, array $result): array
    {
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];

        try {
            $stored = $this->artifacts->persist($body, (string) $action->operation_code, [
                'account_id' => (int) $action->account_id,
                'client_id' => (int) $action->client_id,
                'enrollment_id' => $action->enrollment_id === null ? null : (int) $action->enrollment_id,
            ]);
        } catch (ArtifactStorageUnavailableException) {
            Log::warning('monitoring.das_artifact_storage_unavailable', [
                'request_id' => (string) $action->id,
                'account_id' => (int) $action->account_id,
                'operation_code' => (string) $action->operation_code,
            ]);

            return ['status' => 'failed', 'reason' => 'storage_unavailable'];
        }

        if ($stored['failures'] !== []) {
            return [
                'status' => 'failed',
                'reason' => 'decode_failed',
                'fields' => array_values(array_map(
                    fn (array $failure): string => (string) $failure['field'],
                    $stored['failures'],
                )),
            ];
        }

        $artifact = $stored['artifacts'][0] ?? null;

        if ($artifact === null) {
            return ['status' => 'failed', 'reason' => 'artifact_missing'];
        }

        return [
            'status' => 'stored',
            'ref' => (string) $artifact['ref'],
            'hash_sha256' => (string) $artifact['hash_sha256'],
            'filename' => (string) $artifact['filename'],
            'field' => (string) $artifact['field'],
            'kind' => (string) $artifact['kind'],
        ];
    }

    private function artifactErrorCode(string $reason): string
    {
        return match ($reason) {
            'storage_unavailable' => self::ERROR_ARTIFACT_STORAGE_UNAVAILABLE,
            'decode_failed' => self::ERROR_ARTIFACT_DECODE_FAILED,
            default => self::ERROR_ARTIFACT_MISSING,
        };
    }

    /**
     * Retryable pre-transport refusal that keeps the action alive (e.g. a
     * stale OAuth token was already cleared).
     */
    private function retry(SerproServiceRequest $action, string $reason): SerproServiceRequest
    {
        $attempt = max(1, (int) ($action->metadata['attempt'] ?? 1));
        $delay = $this->backoffFor($attempt);

        $action = $this->persist($action, SerproActionStatus::Pending, [], [
            'error_code' => $reason,
            'retry_after' => $delay,
            'next_attempt_at' => now()->addSeconds($delay)->toIso8601String(),
        ]);

        $this->emitFinished($action, $reason, true, false);
        $this->auditFinished($action);

        return $action;
    }

    /**
     * Terminal (default) or retryable factual failure with no external call.
     */
    private function fail(
        SerproServiceRequest $action,
        string $reason,
        SerproActionStatus $status = SerproActionStatus::Rejected,
    ): SerproServiceRequest {
        $retryable = $status->isRetryable();
        $delay = $retryable ? $this->backoffFor(max(1, (int) ($action->metadata['attempt'] ?? 1))) : null;

        $action = $this->persist($action, $status, [], [
            'error_code' => $reason,
            'retry_after' => $delay,
            'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay)->toIso8601String(),
        ]);

        $this->emitFinished($action, $reason, $retryable, $status->isTerminal());
        $this->auditFinished($action);

        return $action;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $meta
     */
    private function persist(SerproServiceRequest $action, SerproActionStatus $status, array $attributes, array $meta): SerproServiceRequest
    {
        $action->forceFill([
            ...$attributes,
            'status' => $status,
            'metadata' => [...(array) $action->metadata, ...$meta],
        ])->save();

        return $action;
    }

    private function statusFor(SerproClassification $classification): SerproActionStatus
    {
        return match ($classification->status) {
            SerproClassification::SUCCESS => SerproActionStatus::Succeeded,
            SerproClassification::AWAITING_PROTOCOL => SerproActionStatus::Pending,
            SerproClassification::RATE_LIMITED => SerproActionStatus::RateLimited,
            SerproClassification::TRANSIENT => SerproActionStatus::Pending,
            SerproClassification::EXPIRED => SerproActionStatus::Expired,
            SerproClassification::REJECTED => SerproActionStatus::Rejected,
            default => throw new InvalidArgumentException('unsupported_classification'),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function retryDelay(SerproClassification $classification, array $result, int $attempt): int
    {
        if ($classification->retryAfter !== null) {
            return max(1, $classification->retryAfter);
        }

        $eta = is_string($result['eta'] ?? null) ? Carbon::parse((string) $result['eta']) : null;

        if ($eta !== null && $eta->isFuture()) {
            return max(1, (int) ceil(now()->diffInSeconds($eta, false)));
        }

        return $this->backoffFor($attempt);
    }

    private function backoffFor(int $attempt): int
    {
        /** @var list<int|numeric-string> $backoff */
        $backoff = array_values((array) config('monitoring.limits.retry_backoff', [15, 60, 300, 900]));

        if ($backoff === []) {
            return 60;
        }

        return max(1, (int) $backoff[min(max($attempt, 1) - 1, count($backoff) - 1)]);
    }

    private function notYetDue(SerproServiceRequest $action): bool
    {
        if (! $action->status->isRetryable()) {
            return false;
        }

        $next = $action->metadata['next_attempt_at'] ?? null;

        if (! is_string($next) || trim($next) === '') {
            return false;
        }

        try {
            return Carbon::parse($next)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function protocolFor(SerproServiceRequest $action, array $result): ?string
    {
        $incoming = is_scalar($result['protocol'] ?? null) ? trim((string) $result['protocol']) : '';

        return $incoming !== '' ? $incoming : $action->protocol;
    }

    /**
     * @return array<string, mixed>
     */
    private function classificationMeta(SerproClassification $classification): array
    {
        return [
            'status' => $classification->status,
            'code' => $classification->code,
            'retryable' => $classification->retryable,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function protocolFrom(array $body): ?string
    {
        foreach ([$body['protocolo'] ?? null, $body['protocol'] ?? null, data_get($body, 'protocol.protocol_id')] as $candidate) {
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

    private function find(int $accountId, string $idempotencyKey): ?SerproServiceRequest
    {
        return SerproServiceRequest::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function clientFor(SerproServiceRequest $action): ?Client
    {
        return Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $action->account_id)
            ->whereKey($action->client_id)
            ->first();
    }

    private function enrollmentFor(SerproServiceRequest $action): ?MonitoringEnrollment
    {
        if ($action->enrollment_id === null) {
            return null;
        }

        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $action->account_id)
            ->whereKey($action->enrollment_id)
            ->first();
    }

    private function installmentFor(SerproServiceRequest $action): ?ParcelmentInstallment
    {
        if ($action->installment_id === null) {
            return null;
        }

        return ParcelmentInstallment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $action->account_id)
            ->whereKey($action->installment_id)
            ->first();
    }

    private function environmentFor(SerproServiceRequest $action): string
    {
        $environment = $action->metadata['environment'] ?? null;

        if (is_string($environment) && in_array($environment, SerproTransportGate::ENVIRONMENTS, true)) {
            return $environment;
        }

        return $this->gate->environment();
    }

    /**
     * The newest eligible Author of the Account. Fail-closed and factual: no
     * author at all is `author_pending`; authors that exist but lack an active
     * certificate are `author_ineligible`.
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

    private function emitFinished(SerproServiceRequest $action, ?string $errorCode, bool $retryable, bool $terminal): void
    {
        $this->emitSerproEvent('serpro_action_finished', fn () => $this->events->actionFinished(
            (string) $action->id,
            (string) $action->account_id,
            (string) $action->operation_code,
            $action->status->value,
            $action->client_id === null ? null : (string) $action->client_id,
            $errorCode,
            null,
            $retryable,
            $terminal,
            now(),
        ));
    }

    private function auditRequest(
        Client $client,
        string $operationCode,
        ?MonitoringEnrollment $enrollment,
        ?ParcelmentInstallment $installment,
        ?User $actor,
        string $outcome,
        ?string $reason,
        ?SerproServiceRequest $action = null,
    ): void {
        $account = $this->accountFor((int) $client->account_id);

        if ($account === null) {
            return;
        }

        $this->audit->record($actor, 'monitoring.das.requested', [
            'request_id' => $action === null ? null : (string) $action->id,
            'client_id' => (int) $client->getKey(),
            'enrollment_id' => $enrollment === null ? null : (int) $enrollment->getKey(),
            'installment_id' => $installment === null ? null : (int) $installment->getKey(),
            'operation_code' => $operationCode,
            'outcome' => $outcome,
            'reason' => $reason,
        ], $account);
    }

    private function auditFinished(SerproServiceRequest $action): void
    {
        $account = $this->accountFor((int) $action->account_id);

        if ($account === null) {
            return;
        }

        $artifact = $action->metadata['artifact'] ?? null;
        $artifactStatus = is_array($artifact) ? ($artifact['status'] ?? null) : null;

        $this->audit->record(null, 'monitoring.das.finished', [
            'request_id' => (string) $action->id,
            'client_id' => (int) $action->client_id,
            'operation_code' => (string) $action->operation_code,
            'status' => $action->status->value,
            'error_code' => $action->metadata['error_code'] ?? null,
            'artifact' => is_string($artifactStatus) ? $artifactStatus : null,
            'document_available' => $action->document_ref !== null,
        ], $account);
    }

    private function accountFor(int $accountId): ?Account
    {
        return Account::query()->whereKey($accountId)->first();
    }
}
