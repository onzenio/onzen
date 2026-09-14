<?php

namespace App\Integrations\Serpro;

use RuntimeException;

class SerproTermSigner
{
    /**
     * Assina o termo com a chave privada do PFX (SHA-256).
     */
    public function sign(string $canonical, string $pfxContents, string $pfxPassword): string
    {
        if (! openssl_pkcs12_read($pfxContents, $certs, $pfxPassword)) {
            throw new RuntimeException('PFX inválido ou senha não confere para assinatura.');
        }

        $key = openssl_pkey_get_private($certs['pkey'] ?? '');

        if ($key === false || ! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha ao assinar o termo com o Certificado Digital.');
        }

        return base64_encode($signature);
    }

    public function canonical(array $term): string
    {
        ksort($term);

        return implode('|', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($term), $term));
    }
}
