<?php

namespace App\Services\Monitoring;

use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorDocumentType;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Integrations\Serpro\SerproEnvelope;
use App\Models\AccountCertificate;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Termo de Autorização do Autentica-Procurador (`ENVIOXMLASSINADO81`).
 *
 * O autor assina o termo com o Certificado Digital da Account (XMLDSig
 * enveloped, RSA-SHA256) e o serviço o submete em `/Apoiar` com
 * `dados: {"xml": "<base64>"}`. A `data_hora_expiracao` retornada vira a
 * validade do token (fallback: meia-noite seguinte, Brasília); o token
 * repousa no cofre sob ref opaca, nunca em log, job ou tela.
 *
 * Fail-closed: com o transporte fechado (Task 9), sem credencial resolvível
 * ou sem o Certificado Digital da Account, nada é enviado. O PFX e a senha
 * vivem apenas na memória durante a assinatura; o token só existe no cofre e
 * no retorno deste serviço.
 */
final class ProcuradorTermService
{
    public const OPERATION = 'ENVIOXMLASSINADO81';

    public const TOKEN_REF_PREFIX = 'secret:procurador-token-';

    private const XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(
        private readonly SerproTransportGate $gate,
        private readonly SerproCredentialResolver $credentialResolver,
        private readonly OAuthTokenCache $tokenCache,
        private readonly SerproTransport $transport,
        private readonly VaultResolver $vault,
        private readonly AuditService $audit,
    ) {}

    public static function tokenRef(int $accountId): string
    {
        return self::TOKEN_REF_PREFIX.$accountId;
    }

    /**
     * Monta o termo XML com os mesmos elementos/atributos do legado.
     *
     * Os textos são canônicos e validados pelo AUTENTICAPROCURADOR: variação
     * de redação faz o serviço rejeitar o termo (evidência do `_legacy`).
     */
    public function buildTermXml(string $authorDoc, string $authorKind, string $contratanteDoc, string $signedAtYmd, string $validUntilYmd): string
    {
        $authorDigits = preg_replace('/\D/', '', $authorDoc) ?? '';
        $contratanteDigits = preg_replace('/\D/', '', $contratanteDoc) ?? '';
        $authorType = strtoupper($authorKind) === 'PF' ? 'PF' : 'PJ';
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;
        $root = $xml->createElement('termoDeAutorizacao');
        $xml->appendChild($root);
        $dados = $xml->createElement('dados');
        $root->appendChild($dados);
        $sistema = $xml->createElement('sistema');
        $sistema->setAttribute('id', 'API Integra Contador');
        $dados->appendChild($sistema);
        $termo = $xml->createElement('termo');
        $termo->setAttribute('texto', 'Autorizo a empresa CONTRATANTE, identificada neste termo de autorização como DESTINATÁRIO, a executar as requisições dos serviços web disponibilizados pela API INTEGRA CONTADOR, onde terei o papel de AUTOR PEDIDO DE DADOS no corpo da mensagem enviada na requisição do serviço web. Esse termo de autorização está assinado digitalmente com o certificado digital do PROCURADOR ou OUTORGADO DO CONTRIBUINTE responsável, identificado como AUTOR DO PEDIDO DE DADOS.');
        $dados->appendChild($termo);
        $aviso = $xml->createElement('avisoLegal');
        $aviso->setAttribute('texto', 'O acesso a estas informações foi autorizado pelo próprio PROCURADOR ou OUTORGADO DO CONTRIBUINTE, responsável pela informação, via assinatura digital. É dever do destinatário da autorização e consumidor deste acesso observar a adoção de base legal para o tratamento dos dados recebidos conforme artigos 7º ou 11º da LGPD (Lei n.º 13.709, de 14 de agosto de 2018), aos direitos do titular dos dados (art. 9º, 17 e 18, da LGPD) e aos princípios que norteiam todos os tratamentos de dados no Brasil (art. 6º, da LGPD).');
        $dados->appendChild($aviso);
        $finalidade = $xml->createElement('finalidade');
        $finalidade->setAttribute('texto', 'A finalidade única e exclusiva desse TERMO DE AUTORIZAÇÃO, é garantir que o CONTRATANTE apresente a API INTEGRA CONTADOR esse consentimento do PROCURADOR ou OUTORGADO DO CONTRIBUINTE assinado digitalmente, para que possa realizar as requisições dos serviços web da API INTEGRA CONTADOR em nome do AUTOR PEDIDO DE DADOS (PROCURADOR ou OUTORGADO DO CONTRIBUINTE).');
        $dados->appendChild($finalidade);
        $assinatura = $xml->createElement('dataAssinatura');
        $assinatura->setAttribute('data', $signedAtYmd);
        $dados->appendChild($assinatura);
        $vigencia = $xml->createElement('vigencia');
        $vigencia->setAttribute('data', $validUntilYmd);
        $dados->appendChild($vigencia);
        $destinatario = $xml->createElement('destinatario');
        $destinatario->setAttribute('numero', $contratanteDigits);
        $destinatario->setAttribute('nome', 'NOME DA EMPRESA CONTRATANTE');
        $destinatario->setAttribute('tipo', strlen($contratanteDigits) > 11 ? 'PJ' : 'PF');
        $destinatario->setAttribute('papel', 'contratante');
        $dados->appendChild($destinatario);
        $assinadoPor = $xml->createElement('assinadoPor');
        $assinadoPor->setAttribute('numero', $authorDigits);
        $assinadoPor->setAttribute('nome', 'NOME DO AUTOR DO PEDIDO DE DADOS');
        $assinadoPor->setAttribute('tipo', $authorType);
        $assinadoPor->setAttribute('papel', 'autor pedido de dados');
        $dados->appendChild($assinadoPor);

        $unsigned = (string) $xml->saveXML();
        if ($unsigned === '') {
            throw new SerproBlockedException('procurador_term_unbuildable');
        }

        return $unsigned;
    }

    /**
     * Assina o termo com o Certificado Digital da Account, aberto do cofre.
     *
     * @throws SerproBlockedException quando o certificado não existe, expirou
     *                                ou o material guardado é inválido.
     */
    public function signTermXml(SerproRequestAuthor $author, string $unsignedXml): string
    {
        [$pfx, $password] = $this->certificateMaterial($author);

        return $this->signXmlWithPfx($unsignedXml, $pfx, $password);
    }

    /**
     * XMLDSig enveloped sobre o nó `SignedInfo` canônico, RSA-SHA256.
     *
     * O PFX e a senha ficam apenas em memória: nunca são persistidos nem
     * logados. A assinatura é verificada após a inserção — o retorno é o XML
     * assinado ou uma falha fechada.
     *
     * @throws SerproBlockedException
     */
    public function signXmlWithPfx(string $unsignedXml, string $pfxBytes, ?string $password = null): string
    {
        if (! openssl_pkcs12_read($pfxBytes, $certs, (string) $password)) {
            throw new SerproBlockedException('a1_certificate_unavailable');
        }
        $privateKey = openssl_pkey_get_private($certs['pkey'] ?? '');
        $certificate = $certs['cert'] ?? '';
        if (! $privateKey || ! is_string($certificate) || $certificate === '') {
            throw new SerproBlockedException('a1_certificate_unavailable');
        }
        $doc = new \DOMDocument;
        if (! $doc->loadXML($unsignedXml)) {
            throw new SerproBlockedException('procurador_term_unbuildable');
        }
        $canonical = (string) $doc->C14N(true, false);
        $digest = base64_encode(hash('sha256', $canonical, true));
        $signedInfo = '<SignedInfo xmlns="'.self::XMLDSIG_NS.'">'
            .'<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            .'<SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            .'<Reference URI="">'
            .'<Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
            .'<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/></Transforms>'
            .'<DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            ."<DigestValue>{$digest}</DigestValue>"
            .'</Reference></SignedInfo>';
        $pemBody = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $certificate) ?? '';
        $signatureNode = $doc->createElementNS(self::XMLDSIG_NS, 'Signature');
        $fragment = $doc->createDocumentFragment();
        $fragment->appendXML($signedInfo
            .'<SignatureValue xmlns="'.self::XMLDSIG_NS.'"></SignatureValue>'
            .'<KeyInfo xmlns="'.self::XMLDSIG_NS.'">'
            ."<X509Data><X509Certificate>{$pemBody}</X509Certificate></X509Data></KeyInfo>");
        $signatureNode->appendChild($fragment);
        $doc->documentElement->appendChild($signatureNode);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);
        $signedInfoNode = $xpath->query('//ds:Signature/ds:SignedInfo')->item(0);
        $signatureValueNode = $xpath->query('//ds:Signature/ds:SignatureValue')->item(0);
        if (! $signedInfoNode || ! $signatureValueNode) {
            throw new SerproBlockedException('procurador_term_unsignable');
        }
        // A assinatura cobre o `SignedInfo` canônico — os mesmos bytes que o
        // verificador recomputa, nunca a string antes da inserção.
        if (! openssl_sign((string) $signedInfoNode->C14N(true, false), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new SerproBlockedException('procurador_term_unsignable');
        }
        $signatureValueNode->nodeValue = base64_encode($signature);
        $signed = (string) $doc->saveXML();
        if ($signed === '' || ! $this->verifySignature($signed)) {
            throw new SerproBlockedException('procurador_term_unsignable');
        }

        return $signed;
    }

    public function verifySignature(string $signedXml): bool
    {
        $doc = new \DOMDocument;
        if (! $doc->loadXML($signedXml)) {
            return false;
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);
        $signedInfo = $xpath->query('//ds:Signature/ds:SignedInfo')->item(0);
        $signatureValue = $xpath->query('//ds:Signature/ds:SignatureValue')->item(0);
        $certificate = $xpath->query('//ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate')->item(0);
        $digestValue = $xpath->query('//ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue')->item(0);
        if (! $signedInfo || ! $signatureValue || ! $certificate || ! $digestValue) {
            return false;
        }
        $signatureNode = $signedInfo->parentNode;
        if (! $signatureNode) {
            return false;
        }
        // Reparse instead of cloning: DOMDocument clone does not reliably
        // carry the tree, which would silently change the digest input.
        $unsigned = new \DOMDocument;
        if (! $unsigned->loadXML((string) $doc->saveXML())) {
            return false;
        }
        $unsignedXpath = new \DOMXPath($unsigned);
        $unsignedXpath->registerNamespace('ds', self::XMLDSIG_NS);
        $toRemove = $unsignedXpath->query('//ds:Signature');
        if ($toRemove && $toRemove->item(0)?->parentNode) {
            $toRemove->item(0)->parentNode->removeChild($toRemove->item(0));
        }
        $canonical = (string) $unsigned->C14N(true, false);
        if (base64_encode(hash('sha256', $canonical, true)) !== trim($digestValue->textContent)) {
            return false;
        }
        $publicKey = openssl_pkey_get_public("-----BEGIN CERTIFICATE-----\n".chunk_split(trim($certificate->textContent), 64, "\n").'-----END CERTIFICATE-----'."\n");
        if (! $publicKey) {
            return false;
        }

        return openssl_verify($signedInfo->C14N(true, false), (string) base64_decode(trim($signatureValue->textContent)), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Assina o termo do autor com o Certificado Digital da Account e o
     * submete, persistindo token + validade.
     *
     * @return array{token: string, expires_at: CarbonInterface}
     *
     * @throws SerproBlockedException
     */
    public function submitTerm(SerproRequestAuthor $author, string $signedXml, ?User $actor = null): array
    {
        // Fail-closed SERPRO gate: never dispatch the signed envelope while
        // the panel transport switch is off (mirrors the legacy service).
        if ($this->gate->isGated()) {
            throw new SerproBlockedException('serpro_gated');
        }

        // `submitTerm` is the transport boundary: as the last line of defense
        // it never trusts the caller's XML or the author's status. The
        // signature must verify and the author must be eligible before any
        // credential resolution, envelope build or external traffic.
        if (! $this->verifySignature($signedXml)) {
            throw new SerproBlockedException('procurador_term_unsigned');
        }

        if (! $author->isEligible()) {
            throw new SerproBlockedException('author_ineligible');
        }

        $environment = $this->gate->environment();
        $contract = SerproContract::query()->where('environment', $environment)->first();
        $credentials = $this->credentialResolver->resolve($contract);
        $credentialRef = (string) ($contract?->credential_ref ?? '');

        $oauth = $this->tokenCache->get($credentials, $environment, $credentialRef);

        $envelope = SerproEnvelope::make(
            SerproEnvelope::partyFor($credentials->contratanteDoc),
            SerproEnvelope::partyFor($author->document, $author->document_type->value),
            [],
            self::OPERATION,
            ['xml' => base64_encode($signedXml)],
        );

        $response = $this->transport->call(
            ProcurationCatalog::pathFor(self::OPERATION),
            $envelope,
            $oauth['access_token'],
            array_filter([
                'idempotency_key' => 'termo:'.(string) Str::uuid(),
                'jwt_token' => $oauth['jwt_token'],
                'environment' => $environment,
            ], fn (mixed $value): bool => $value !== null),
        );

        if ($this->tokenCache->forgetIfUnauthorized((int) ($response['status'] ?? 0), $environment, $credentialRef)) {
            throw new SerproBlockedException('serpro_oauth_unauthorized');
        }

        $parsed = $this->parseTokenResponse(is_array($response['body'] ?? null) ? $response['body'] : []);

        if ($parsed === null) {
            $parsed = $this->parseNotModifiedResponse($response, $this->storedToken($author));
        }

        if ($parsed === null) {
            throw new SerproBlockedException('procurador_token_missing');
        }

        $expiresAt = Carbon::parse($parsed['expires_at'])->setTimezone(config('app.timezone', 'UTC'));
        $parsed['expires_at'] = $expiresAt;

        $this->persistToken($author, $parsed['token'], $expiresAt);

        $this->audit->record($actor, 'monitoring.procurador_term_submitted', [
            'author_id' => $author->id,
            'environment' => $environment,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $author->account);

        return $parsed;
    }

    /**
     * Monta, assina e submete o termo do autor numa única operação (usada
     * pela renovação diária).
     *
     * @return array{token: string, expires_at: CarbonInterface}
     *
     * @throws SerproBlockedException
     */
    public function renewTerm(SerproRequestAuthor $author, ?User $actor = null): array
    {
        if ($this->gate->isGated()) {
            throw new SerproBlockedException('serpro_gated');
        }

        if (! $author->isEligible()) {
            throw new SerproBlockedException('author_ineligible');
        }

        $contract = SerproContract::query()->where('environment', $this->gate->environment())->first();
        $credentials = $this->credentialResolver->resolve($contract);
        $today = Carbon::now('America/Sao_Paulo');

        $unsigned = $this->buildTermXml(
            $author->document,
            $author->document_type === AuthorDocumentType::Pj ? 'PJ' : 'PF',
            $credentials->contratanteDoc,
            $today->format('Ymd'),
            $today->copy()->addDay()->format('Ymd'),
        );

        return $this->submitTerm($author, $this->signTermXml($author, $unsigned), $actor);
    }

    /**
     * @return array{token: string, expires_at: CarbonInterface}|null
     */
    public function parseNotModifiedResponse(array $response, ?string $currentToken = null): ?array
    {
        if ((int) ($response['status'] ?? 0) !== 304) {
            return null;
        }

        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $token = self::etagProcuradorToken($headers) ?? $currentToken;

        if (! is_string($token) || $token === '') {
            return null;
        }

        return ['token' => $token, 'expires_at' => $this->fallbackExpiry()];
    }

    /**
     * Walk the response (and JSON-encoded `dados`/`data` levels) looking for
     * the procurador token and its official expiry.
     *
     * @return array{token: string, expires_at: CarbonInterface}|null
     */
    public function parseTokenResponse(array $body): ?array
    {
        $node = $body;

        for ($depth = 0; $depth < 4; $depth++) {
            $token = $this->stringValue($node, 'autenticar_procurador_token');

            if ($token !== null) {
                return ['token' => $token, 'expires_at' => $this->parseExpiry($node) ?? $this->fallbackExpiry()];
            }

            $next = $this->innerPayload($node);
            if ($next === [] || $next === $node) {
                break;
            }

            $node = $next;
        }

        return null;
    }

    public function fallbackExpiry(): CarbonInterface
    {
        return Carbon::now('America/Sao_Paulo')->addDay()->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function parseExpiry(array $node): ?CarbonInterface
    {
        $raw = (string) ($node['data_hora_expiracao'] ?? '');

        if (trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Renova quando falta token ou o vencimento está a menos de 24h. */
    public function shouldRenew(SerproRequestAuthor $author): bool
    {
        $expiresAt = data_get($author->metadata, 'token_expires_at');

        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return true;
        }

        try {
            return Carbon::parse($expiresAt)->lt(now()->addHours(24));
        } catch (\Throwable) {
            return true;
        }
    }

    public function persistToken(SerproRequestAuthor $author, string $token, CarbonInterface $expiresAt): void
    {
        // Eloquent grava datas sem offset: normaliza o instante para o
        // timezone da aplicação antes de persistir (a `data_hora_expiracao`
        // oficial chega em horário de Brasília).
        $expiresAt = Carbon::parse($expiresAt)->setTimezone(config('app.timezone', 'UTC'));
        $ref = self::tokenRef((int) $author->account_id);

        $this->vault->put($ref, $token);

        $metadata = is_array($author->metadata) ? $author->metadata : [];
        $metadata['token_ref'] = $ref;
        $metadata['token_expires_at'] = $expiresAt->toIso8601String();
        $author->update(['metadata' => $metadata]);
    }

    /**
     * Fail-closed certificate resolution: a missing, expired or undecodable
     * Account certificate blocks the signature with a factual reason.
     *
     * @return array{0: string, 1: string}
     */
    private function certificateMaterial(SerproRequestAuthor $author): array
    {
        $certificate = AccountCertificate::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $author->account_id)
            ->first();

        if ($certificate === null || $certificate->isExpired()) {
            throw new SerproBlockedException('account_certificate_unavailable');
        }

        $raw = $this->vault->get($certificate->vault_ref);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($data)) {
            throw new SerproBlockedException('account_certificate_unavailable');
        }

        $encoded = (string) ($data['pfx_base64'] ?? '');
        $pfx = $encoded === '' ? false : base64_decode($encoded, true);

        if (! is_string($pfx) || $pfx === '') {
            throw new SerproBlockedException('account_certificate_unavailable');
        }

        return [$pfx, (string) ($data['certificate_password'] ?? '')];
    }

    private function storedToken(SerproRequestAuthor $author): ?string
    {
        $ref = (string) data_get($author->metadata, 'token_ref', '');

        if ($ref === '') {
            $ref = self::tokenRef((int) $author->account_id);
        }

        $token = $this->vault->get($ref);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function stringValue(array $node, string $key): ?string
    {
        $value = $node[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function innerPayload(array $node): array
    {
        foreach (['dados', 'data'] as $key) {
            $value = $node[$key] ?? null;

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } elseif (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private static function etagProcuradorToken(array $headers): ?string
    {
        $values = $headers['etag'] ?? $headers['Etag'] ?? $headers['ETag'] ?? $headers['ETAG'] ?? null;
        if (is_string($values)) {
            $values = [$values];
        }
        if (! is_array($values)) {
            return null;
        }
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = trim(substr($candidate, 2));
            }
            $candidate = trim($candidate, '"');
            $parts = array_pad(explode(':', $candidate, 2), 2, '');
            if (trim($parts[0]) !== 'autenticar_procurador_token') {
                continue;
            }
            if (trim($parts[1]) === '') {
                continue;
            }

            return trim($parts[1]);
        }

        return null;
    }
}
