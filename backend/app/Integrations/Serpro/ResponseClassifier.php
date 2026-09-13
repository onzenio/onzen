<?php

namespace App\Integrations\Serpro;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Classificação de resposta do Integra Contador (porte do runtime `_legacy`).
 *
 * Mapeamento, na ordem:
 *
 * 1. `expired`: corpo com `expired: true` ou `status` `expired`/`expirado` →
 *    protocolo expirado (terminal).
 * 2. `awaiting_protocol`: `obtained` falso e HTTP 202 ou corpo com
 *    `protocol`/`protocolo` → protocolo pendente, retryable (polling).
 * 3. `rate_limited`: HTTP 429 → retryable com `Retry-After`.
 * 4. `transient`: HTTP 408/504 (`timeout`), status ausente (`transport_error`)
 *    ou HTTP ≥ 500 (`transient_error`) → retryable com `Retry-After`.
 * 5. `rejected`: demais HTTP ≥ 400 → definitivo, sem retry.
 * 6. `success`: qualquer outro resultado.
 *
 * `Retry-After` é lido dos headers (array ou escalar) ou de `retry_after` no
 * corpo, sempre em segundos e nunca abaixo de 1. `classifyException` cobre
 * falhas de transporte lançadas antes de existir resposta HTTP. Nenhum
 * conteúdo fiscal é lido, logado ou propagado.
 */
final class ResponseClassifier
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    public function classify(int $status, array $body = [], array $headers = []): SerproClassification
    {
        if (($body['expired'] ?? false) === true || in_array(strtolower((string) ($body['status'] ?? '')), ['expired', 'expirado'], true)) {
            return new SerproClassification(SerproClassification::EXPIRED, 'protocol_expired');
        }

        $obtained = (bool) ($body['obtained'] ?? data_get($body, 'protocol.obtained', false));
        if (! $obtained && ($status === 202 || isset($body['protocol']) || isset($body['protocolo']))) {
            return new SerproClassification(
                SerproClassification::AWAITING_PROTOCOL,
                'protocol_pending',
                true,
                $this->retryAfter($body, $headers),
            );
        }

        if ($status === 429) {
            return new SerproClassification(
                SerproClassification::RATE_LIMITED,
                'rate_limited',
                true,
                $this->retryAfter($body, $headers),
            );
        }

        if ($status === 408 || $status === 504) {
            return new SerproClassification(
                SerproClassification::TRANSIENT,
                'timeout',
                true,
                $this->retryAfter($body, $headers),
            );
        }

        if ($status === 0) {
            return new SerproClassification(SerproClassification::TRANSIENT, 'transport_error', true);
        }

        if ($status >= 500) {
            return new SerproClassification(
                SerproClassification::TRANSIENT,
                'transient_error',
                true,
                $this->retryAfter($body, $headers),
            );
        }

        if ($status >= 400) {
            return new SerproClassification(SerproClassification::REJECTED, 'definitive_rejection');
        }

        return new SerproClassification(SerproClassification::SUCCESS, 'ok');
    }

    /**
     * Falha de transporte sem resposta HTTP: sempre transitória.
     */
    public function classifyException(Throwable $exception): SerproClassification
    {
        return new SerproClassification(
            SerproClassification::TRANSIENT,
            $exception instanceof ConnectionException ? 'timeout' : 'transport_error',
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    private function retryAfter(array $body, array $headers): ?int
    {
        $value = $headers['Retry-After'] ?? $headers['retry-after'] ?? $body['retry_after'] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }
}
