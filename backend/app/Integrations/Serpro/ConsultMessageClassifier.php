<?php

namespace App\Integrations\Serpro;

/**
 * Classificação de mensagem de negócio do PGDAS-D (porte do `_legacy`).
 *
 * Refina a classificação HTTP quando o corpo traz um código de negócio:
 *
 * - `MSG_ISN_{003,005,006,007,027,028}` (ou `EntradaIncorreta-PGDASD`) →
 *   rejeição definitiva, sem retry;
 * - `MSG_ISN_{001,012}` (ou `Erro-PGDASD`) → transitório retryable, herdando
 *   o `Retry-After` HTTP ou 60s como padrão;
 * - `Sucesso-PGDASD` → sucesso de negócio (mesmo sobre HTTP não-2xx);
 * - qualquer outro código, corpo vazio ou HTTP `rate_limited`/`expired`
 *   mantém a classificação HTTP intacta.
 */
final class ConsultMessageClassifier
{
    /** @var list<string> */
    private const INVALID_INPUT = ['003', '005', '006', '007', '027', '028'];

    /** @var list<string> */
    private const TRANSIENT = ['001', '012'];

    /**
     * @param  array<string, mixed>  $body
     */
    public function classify(array $body, SerproClassification $http): SerproClassification
    {
        $code = (string) ($body['codigo'] ?? $body['code'] ?? $body['status'] ?? '');

        if ($code === '' || in_array($http->status, [SerproClassification::RATE_LIMITED, SerproClassification::EXPIRED], true)) {
            return $http;
        }

        if (str_contains($code, 'Sucesso-PGDASD')) {
            return new SerproClassification(SerproClassification::SUCCESS, 'ok');
        }

        if (preg_match('/MSG_ISN_(\d+)/', $code, $matches) !== 1) {
            return $http;
        }

        $isn = $matches[1];

        if (in_array($isn, self::INVALID_INPUT, true) || str_contains($code, 'EntradaIncorreta-PGDASD')) {
            return new SerproClassification(SerproClassification::REJECTED, $code);
        }

        if (in_array($isn, self::TRANSIENT, true) || str_contains($code, 'Erro-PGDASD')) {
            return new SerproClassification(
                SerproClassification::TRANSIENT,
                $code,
                true,
                $http->retryAfter ?? 60,
            );
        }

        return $http;
    }
}
