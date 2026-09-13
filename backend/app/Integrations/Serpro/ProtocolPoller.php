<?php

namespace App\Integrations\Serpro;

use App\Contracts\SerproTransport;
use App\Exceptions\SerproBlockedException;
use App\Models\MonitoringRun;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Polling do protocolo de uma execução em `awaiting_protocol`.
 *
 * A consulta repete o caminho da operação com `{protocol, poll: true}` como
 * `pedidoDados.dados` — nunca a solicitação original. O resultado final
 * (`obtained`, ou 200 com `result`/`data`/`dados` sem protocolo pendente) é
 * cacheado por Account/ambiente/protocolo, para que uma redelivery da fila
 * não invoque a SERPRO de novo; respostas ainda pendentes — mesmo em HTTP 200
 * — não são cacheadas. Operações do catálogo proibidas no polling são
 * recusadas antes de qualquer tráfego.
 */
final class ProtocolPoller
{
    public function __construct(private readonly SerproTransport $transport) {}

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $options
     * @return array{status: int, body: array<string, mixed>, headers?: array<string, mixed>}
     *
     * @throws InvalidArgumentException quando não há protocolo persistido.
     * @throws SerproBlockedException quando a operação é proibida no polling.
     */
    public function poll(
        MonitoringRun $run,
        string $protocol,
        array $envelope,
        string $accessToken,
        array $options = [],
    ): array {
        $protocol = trim($protocol);

        if ($protocol === '') {
            throw new InvalidArgumentException('protocol_required');
        }

        if (ConsultCatalog::isForbiddenPolling((string) $run->operation_code)) {
            throw new SerproBlockedException('protocol_polling_forbidden');
        }

        $cacheKey = $this->cacheKey($run, $protocol);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['status'], $cached['body'])) {
            return $cached;
        }

        $response = $this->transport->call(
            ProcurationCatalog::pathFor((string) $run->operation_code),
            $envelope,
            $accessToken,
            $options,
        );

        if ($this->isFinal($response)) {
            Cache::forever($cacheKey, $response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isFinal(array $response): bool
    {
        $status = (int) ($response['status'] ?? 0);
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $obtained = (bool) ($body['obtained'] ?? data_get($body, 'protocol.obtained', false));
        $hasProtocol = isset($body['protocol']) || isset($body['protocolo']);

        // A pending protocol is never final, even on HTTP 200 with data:
        // caching it would redeliver a still-pending body forever.
        if (! $obtained && ($status === 202 || $hasProtocol)) {
            return false;
        }

        if ($obtained) {
            return true;
        }

        if ($status !== 200) {
            return false;
        }

        return array_key_exists('result', $body)
            || array_key_exists('data', $body)
            || array_key_exists('dados', $body);
    }

    private function cacheKey(MonitoringRun $run, string $protocol): string
    {
        return 'serpro:protocol:'.hash('sha256', implode('|', [
            (string) $run->account_id,
            (string) $run->environment,
            $protocol,
        ]));
    }
}
