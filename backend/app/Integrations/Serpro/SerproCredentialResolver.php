<?php

namespace App\Integrations\Serpro;

use App\Contracts\VaultResolver;
use App\Exceptions\SerproBlockedException;
use App\Models\SerproContract;

/**
 * Resolves the Contratante SERPRO credential from the opaque vault ref on
 * {@see SerproContract}.
 *
 * Accepts the JSON form (`client_id`/`e_cnpj`, `consumer_secret`,
 * `contratante_doc`) and the legacy pair form (`e_cnpj:consumer_secret`).
 * Refs must be `secret:`; anything missing, unresolved or invalid fails
 * closed with a factual reason code. Secrets are never logged or returned
 * outside {@see SerproCredentials}.
 */
final class SerproCredentialResolver
{
    public function __construct(private readonly VaultResolver $vault) {}

    public function resolve(?SerproContract $contract): SerproCredentials
    {
        if ($contract === null) {
            throw new SerproBlockedException('serpro_credential_missing');
        }

        return $this->resolveRef($contract->credential_ref);
    }

    public function resolveRef(?string $ref): SerproCredentials
    {
        if ($ref === null || $ref === '' || ! str_starts_with($ref, 'secret:') || $ref === 'secret:') {
            throw new SerproBlockedException('serpro_credential_ref_invalid');
        }

        $raw = $this->vault->get($ref);
        if ($raw === null) {
            throw new SerproBlockedException('serpro_credential_unresolved');
        }

        return $this->parse($raw);
    }

    /**
     * @param  array<string, mixed>|string  $raw
     */
    private function parse(array|string $raw): SerproCredentials
    {
        $data = is_array($raw) ? $raw : json_decode($raw, true);
        if (! is_array($data)) {
            [$eCnpj, $secret] = array_pad(explode(':', $raw, 2), 2, '');
            $data = ['client_id' => $eCnpj, 'consumer_secret' => $secret];
        }

        $eCnpj = (string) ($data['client_id'] ?? $data['e_cnpj'] ?? $data['ecnpj'] ?? '');
        $secret = (string) ($data['consumer_secret'] ?? $data['secret'] ?? '');
        if ($eCnpj === '' || $secret === '') {
            throw new SerproBlockedException('serpro_credential_invalid');
        }

        // A consumer key opaca não é o CNPJ da contratante: o documento
        // explícito vence, com fallback para a própria chave.
        $explicitDoc = (string) ($data['contratante_doc'] ?? $data['contratante'] ?? '');
        $contratanteDoc = preg_replace('/\D/', '', $explicitDoc) !== '' ? $explicitDoc : $eCnpj;

        // Material de mTLS opcional: ou vem completo (PFX + senha) ou não vem,
        // senão o transporte falharia no meio do caminho — falha-se aqui.
        $certificate = (string) ($data['certificate'] ?? '');
        $certificatePassword = (string) ($data['certificate_password'] ?? '');
        if (($certificate === '') !== ($certificatePassword === '')) {
            throw new SerproBlockedException('serpro_credential_invalid');
        }

        return new SerproCredentials(
            $eCnpj,
            $secret,
            $contratanteDoc,
            $certificate !== '' ? $certificate : null,
            $certificatePassword !== '' ? $certificatePassword : null,
        );
    }
}
