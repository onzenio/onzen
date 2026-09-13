<?php

namespace App\Services\Monitoring;

use App\Contracts\SerproTransport;
use App\Enums\AuthorStatus;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Integrations\Serpro\SerproEnvelope;
use App\Models\Client;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Live verification call of a Client's outorga (`OBTERPROCURACAO41`,
 * `/Consultar`, without the `autenticar_procurador_token` header).
 *
 * The envelope carries the Client as `outorgante`/`contribuinte` and the
 * Account author as `outorgado`/`autorPedidoDados`; the official response is
 * parsed by {@see ProcurationVerifier::parseGranted()}.
 *
 * Fail-closed, in this order before any traffic: closed transport gate, an
 * active/unexpired Account certificate and an eligible author, then the
 * Contratante credential and OAuth token. Every refusal is a factual reason
 * code; certificate and credential material never leave the request.
 */
final class ProcurationVerificationCall
{
    public const OPERATION = 'OBTERPROCURACAO41';

    public function __construct(
        private readonly SerproTransportGate $gate,
        private readonly SerproCredentialResolver $credentialResolver,
        private readonly OAuthTokenCache $tokenCache,
        private readonly SerproTransport $transport,
        private readonly ProcurationA1Authenticator $authenticator,
    ) {}

    /**
     * @return array<string, string> código de procuração → vencimento `Y-m-d`
     *
     * @throws SerproBlockedException
     */
    public function fetchGrants(Client $client, ?SerproRequestAuthor $author = null, ?CarbonInterface $at = null): array
    {
        if ($this->gate->isGated()) {
            throw new SerproBlockedException('serpro_gated');
        }

        $account = $client->account;

        if ($account === null) {
            throw new SerproBlockedException('client_account_missing');
        }

        $author ??= $this->eligibleAuthor($client);

        if ($author === null) {
            throw new SerproBlockedException('author_pending');
        }

        $this->authenticator->authenticate($account, $client, $author, $at);

        $environment = $this->gate->environment();
        $contract = SerproContract::query()->where('environment', $environment)->first();
        $credentials = $this->credentialResolver->resolve($contract);
        $credentialRef = (string) ($contract?->credential_ref ?? '');

        $oauth = $this->tokenCache->get($credentials, $environment, $credentialRef);

        $authorParty = SerproEnvelope::partyFor($author->document, $author->document_type->value);
        $clientParty = SerproEnvelope::partyFor($client->cnpj);

        $envelope = SerproEnvelope::make(
            SerproEnvelope::partyFor($credentials->contratanteDoc),
            $authorParty,
            $clientParty,
            self::OPERATION,
            [
                'outorgante' => $clientParty['numero'],
                'tipoOutorgante' => $clientParty['tipo'],
                'outorgado' => $authorParty['numero'],
                'tipoOutorgado' => $authorParty['tipo'],
            ],
        );

        $response = $this->transport->call(
            ProcurationCatalog::pathFor(self::OPERATION),
            $envelope,
            $oauth['access_token'],
            array_filter([
                'idempotency_key' => 'obter-procuracao:'.(string) Str::uuid(),
                'jwt_token' => $oauth['jwt_token'],
                'environment' => $environment,
            ], fn (mixed $value): bool => $value !== null),
        );

        $status = (int) ($response['status'] ?? 0);

        if ($this->tokenCache->forgetIfUnauthorized($status, $environment, $credentialRef)) {
            throw new SerproBlockedException('serpro_oauth_unauthorized');
        }

        if ($status < 200 || $status >= 300) {
            throw new SerproBlockedException('procuracao_verificacao_indisponivel');
        }

        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $dados = $body['dados'] ?? $body['data'] ?? $body;

        return ProcurationVerifier::parseGranted($dados);
    }

    private function eligibleAuthor(Client $client): ?SerproRequestAuthor
    {
        return SerproRequestAuthor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('status', AuthorStatus::Active)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
