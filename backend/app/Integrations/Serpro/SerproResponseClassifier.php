<?php

namespace App\Integrations\Serpro;

class SerproResponseClassifier
{
    public const SUCCESS = 'success';

    public const TRANSIENT = 'transient';

    public const FATAL = 'fatal';

    public const PROTOCOL = 'protocol';

    /**
     * Classifica a resposta para dirigir retry, backoff e polling.
     *
     * @param  array<string, mixed>  $payload
     * @return array{outcome: string, backoff_seconds: int|null}
     */
    public static function classify(?int $statusCode, array $payload = [], int $attempt = 1, int $backoffBase = 60): array
    {
        // Protocolo pendente: poll sem repetir a solicitação original.
        if ($statusCode === 202 || self::hasPendingProtocol($payload)) {
            return ['outcome' => self::PROTOCOL, 'backoff_seconds' => self::backoffForAttempt($attempt, $backoffBase)];
        }

        // Rejeição definitiva de negócio: encerra sem retry.
        if (self::isDefinitiveRejection($statusCode, $payload)) {
            return ['outcome' => self::FATAL, 'backoff_seconds' => null];
        }

        // Sucesso.
        if ($statusCode !== null && $statusCode >= 200 && $statusCode < 300) {
            return ['outcome' => self::SUCCESS, 'backoff_seconds' => null];
        }

        // Todo o resto (429, timeout, 5xx, rede): transitório com backoff.
        return ['outcome' => self::TRANSIENT, 'backoff_seconds' => self::backoffForAttempt($attempt, $backoffBase)];
    }

    public static function backoffForAttempt(int $attempt, int $base = 60): int
    {
        return $base * (2 ** max(0, $attempt - 1));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function hasPendingProtocol(array $payload): bool
    {
        if (! isset($payload['protocolo'])) {
            return false;
        }

        $situacao = strtolower((string) ($payload['situacao'] ?? 'pendente'));

        return in_array($situacao, ['pendente', 'em_processamento', 'aguardando'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function isDefinitiveRejection(?int $statusCode, array $payload): bool
    {
        if (($payload['rejeicao_definitiva'] ?? false) === true) {
            return true;
        }

        if ($statusCode === 422) {
            return true;
        }

        if ($statusCode === 400 && isset($payload['codigo_erro'])) {
            return true;
        }

        return false;
    }
}
