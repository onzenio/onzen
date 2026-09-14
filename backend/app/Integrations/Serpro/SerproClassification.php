<?php

namespace App\Integrations\Serpro;

/**
 * Classificação factual de uma resposta SERPRO (HTTP e mensagem de negócio).
 *
 * `status` é a classe de resultado consumida pelo executor; `code` é o código
 * factual registrado na tentativa (HTTP ou mensagem de negócio, ex.:
 * `MSG_ISN_027`); `retryable` e `retryAfter` dirigem o retry/backoff.
 */
final readonly class SerproClassification
{
    public const SUCCESS = 'success';

    public const AWAITING_PROTOCOL = 'awaiting_protocol';

    public const EXPIRED = 'expired';

    public const RATE_LIMITED = 'rate_limited';

    public const TRANSIENT = 'transient';

    public const REJECTED = 'rejected';

    public function __construct(
        public string $status,
        public string $code,
        public bool $retryable = false,
        public ?int $retryAfter = null,
    ) {}
}
