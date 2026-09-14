<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Integrations\Serpro\SerproEnvelope;
use App\Integrations\Serpro\SerproTransport;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproServiceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ActionService
{
    public function __construct(
        private readonly SerproTransport $transport,
        private readonly VaultService $vault,
        private readonly ArtifactStore $artifacts,
        private readonly AuditService $audit,
    ) {}

    public function requestEmission(
        Account $account,
        User $actor,
        int $clientId,
        string $kind,
        string $idempotencyKey,
        bool $confirmed,
    ): SerproServiceRequest {
        if ($actor->role === UserRole::User) {
            throw new AuthorizationException('Emissão de DAS restrita a admin e operator.');
        }

        if (! in_array($kind, SerproServiceRequest::kinds(), true)) {
            throw ValidationException::withMessages(['kind' => 'Ação fiscal desconhecida.']);
        }

        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Emissão de DAS exige confirmação explícita.']);
        }

        $client = Client::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->whereKey($clientId)
            ->first();

        if ($client === null) {
            throw (new ModelNotFoundException)->setModel(Client::class, $clientId);
        }

        $existing = SerproServiceRequest::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $contract = SerproContract::query()->first();

        if ($contract === null || ! $contract->transport_approved
            || (bool) config('monitoring.dry_run', true)
            || empty($contract->contractor_document)) {
            throw new RuntimeException('Transporte não aprovado para ações fiscais: emissão recusada.', 422);
        }

        $author = SerproRequestAuthor::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('status', SerproRequestAuthor::STATUS_ACTIVE)
            ->with('certificate')
            ->first();

        if ($author === null || ! $author->isEligible()) {
            throw new RuntimeException('Autor do Pedido de Dados inelegível: emissão bloqueada.', 422);
        }

        $serviceRequest = SerproServiceRequest::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'kind' => $kind,
            'idempotency_key' => $idempotencyKey,
            'status' => SerproServiceRequest::RUNNING,
            'confirmed' => true,
        ]);

        $this->execute($serviceRequest, $contract, $author, $client, $account);

        $this->audit->record($actor, $account->id, $account->id, 'serpro_action.requested', [
            'kind' => $kind,
            'client_id' => $client->id,
            'idempotency_key' => $idempotencyKey,
            'status' => $serviceRequest->refresh()->status,
        ]);

        return $serviceRequest->refresh();
    }

    public function pollProtocol(SerproServiceRequest $serviceRequest): SerproServiceRequest
    {
        if ($serviceRequest->protocol === null) {
            throw new RuntimeException('Ação sem protocolo para consultar.');
        }

        $account = $serviceRequest->account;
        $platform = Account::query()->where('profile', 'A')->first() ?? $account;

        $certificate = AccountCertificate::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->first();

        $response = $this->transport->request('consultar-protocolo', [
            'protocolo' => $serviceRequest->protocol,
        ], [
            'platform_account' => $platform,
            'idempotency_key' => $serviceRequest->idempotency_key.':poll',
            'pfx_contents' => $certificate ? $this->vault->get($certificate->pfx_ref) : null,
            'pfx_password' => $certificate ? $this->vault->get($certificate->password_ref) : null,
        ]);

        $this->applyResponse($serviceRequest, $response, (int) $account->id);

        return $serviceRequest->refresh();
    }

    private function execute(
        SerproServiceRequest $serviceRequest,
        SerproContract $contract,
        SerproRequestAuthor $author,
        Client $client,
        Account $account,
    ): void {
        $platform = Account::query()->where('profile', 'A')->first() ?? $account;

        $certificate = AccountCertificate::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->first();

        $envelope = SerproEnvelope::build(
            $serviceRequest->kind,
            (string) $contract->contractor_document,
            $author->document,
            $client->cnpj,
        );

        try {
            $response = $this->transport->request($serviceRequest->kind, $envelope, [
                'platform_account' => $platform,
                'idempotency_key' => $serviceRequest->idempotency_key,
                'pfx_contents' => $certificate ? $this->vault->get($certificate->pfx_ref) : null,
                'pfx_password' => $certificate ? $this->vault->get($certificate->password_ref) : null,
            ]);

            $this->applyResponse($serviceRequest, $response, (int) $account->id);
        } catch (\Throwable $e) {
            $serviceRequest->forceFill([
                'status' => SerproServiceRequest::FAILED,
                'failure_reason' => substr($e->getMessage(), 0, 500),
            ])->save();

            Log::warning('serpro.action_failed', ['request_id' => $serviceRequest->id]);
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function applyResponse(SerproServiceRequest $serviceRequest, array $response, int $accountId): void
    {
        $situacao = strtolower((string) ($response['situacao'] ?? ''));

        if (isset($response['protocolo']) && $situacao !== 'concluido') {
            $serviceRequest->forceFill([
                'status' => SerproServiceRequest::AWAITING_PROTOCOL,
                'protocol' => $response['protocolo'],
            ])->save();

            return;
        }

        $updates = [
            'status' => SerproServiceRequest::COMPLETED,
            'protocol' => $response['protocolo'] ?? $serviceRequest->protocol,
        ];

        $das = $response['das'] ?? $response['guia'] ?? null;

        if (is_string($das) && $das !== '') {
            $decoded = base64_decode($das, true);

            if ($decoded !== false) {
                $stored = $this->artifacts->put($accountId, $decoded, 'application/pdf');
                $updates['artifact_ref'] = $stored['ref'];
            }
        }

        $serviceRequest->forceFill($updates)->save();
    }
}
