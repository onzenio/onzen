<?php

namespace App\Integrations\Serpro;

use App\Contracts\SerproTransport;
use JsonSerializable;

/**
 * Resolved Contratante SERPRO credentials.
 *
 * The only object that carries the consumer secret and the mTLS material in
 * memory. It is explicitly non-serializable: `serialize()`, `json_encode()`
 * and debug output never include a secret. Secrets leave the object only
 * through {@see toTransportCredentials()} at the transport boundary.
 */
final readonly class SerproCredentials implements JsonSerializable
{
    public function __construct(
        public string $eCnpj,
        public string $consumerSecret,
        public string $contratanteDoc,
        public ?string $certificate = null,
        public ?string $certificatePassword = null,
    ) {}

    /**
     * Explicit boundary payload for {@see SerproTransport::token()}.
     *
     * @return array{
     *     e_cnpj: string,
     *     consumer_secret: string,
     *     certificate: string|null,
     *     certificate_password: string|null
     * }
     */
    public function toTransportCredentials(): array
    {
        return [
            'e_cnpj' => $this->eCnpj,
            'consumer_secret' => $this->consumerSecret,
            'certificate' => $this->certificate,
            'certificate_password' => $this->certificatePassword,
        ];
    }

    /**
     * @return array{resolved: false}
     */
    public function jsonSerialize(): array
    {
        return ['resolved' => false];
    }

    /**
     * @return array{resolved: false}
     */
    public function __serialize(): array
    {
        return ['resolved' => false];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'eCnpj' => '[redacted]',
            'consumerSecret' => '[redacted]',
            'contratanteDoc' => '[redacted]',
            'certificate' => '[redacted]',
            'certificatePassword' => '[redacted]',
        ];
    }

    public function __toString(): string
    {
        return 'SerproCredentials(redacted)';
    }
}
