<?php

namespace Tests\Unit;

use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\ProtocolPoller;
use App\Models\MonitoringRun;
use InvalidArgumentException;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class ProtocolPollerTest extends TestCase
{
    public function test_poll_uses_the_operation_path_and_caches_a_final_result_for_redelivery(): void
    {
        $transport = new FakeSerproTransport(callResponses: [[
            'status' => 200,
            'body' => [
                'obtained' => true,
                'protocol' => ['protocol_id' => 'PROTO-1', 'obtained' => true],
                'dados' => ['situacao' => 'regular'],
            ],
        ]]);
        $poller = new ProtocolPoller($transport);
        $envelope = ['pedidoDados' => ['dados' => '{"protocol":"PROTO-1","poll":true}']];

        $first = $poller->poll($this->awaitingRun(), 'PROTO-1', $envelope, 'access-token');
        $second = $poller->poll($this->awaitingRun(), 'PROTO-1', $envelope, 'access-token');

        $this->assertSame($first, $second);
        $this->assertCount(1, $transport->callCalls);
        $this->assertSame('/Consultar', $transport->callCalls[0]['path']);
        $this->assertSame('access-token', $transport->callCalls[0]['access_token']);
        $this->assertSame($envelope, $transport->callCalls[0]['envelope']);
    }

    public function test_a_pending_response_is_not_cached(): void
    {
        $transport = new FakeSerproTransport(callResponses: [
            ['status' => 202, 'body' => ['protocol' => ['protocol_id' => 'PROTO-1', 'obtained' => false]]],
            ['status' => 200, 'body' => ['obtained' => true, 'dados' => []]],
        ]);
        $poller = new ProtocolPoller($transport);

        $poller->poll($this->awaitingRun(), 'PROTO-1', [], 'access-token');
        $second = $poller->poll($this->awaitingRun(), 'PROTO-1', [], 'access-token');

        $this->assertCount(2, $transport->callCalls);
        $this->assertTrue($second['body']['obtained']);
    }

    public function test_a_pending_200_body_is_not_cached(): void
    {
        $transport = new FakeSerproTransport(callResponses: [
            ['status' => 200, 'body' => [
                'protocol' => ['protocol_id' => 'PROTO-1', 'obtained' => false],
                'dados' => ['situacao' => 'processando'],
            ]],
            ['status' => 200, 'body' => ['obtained' => true, 'dados' => []]],
        ]);
        $poller = new ProtocolPoller($transport);

        $first = $poller->poll($this->awaitingRun(), 'PROTO-1', [], 'access-token');
        $second = $poller->poll($this->awaitingRun(), 'PROTO-1', [], 'access-token');

        $this->assertFalse((bool) data_get($first, 'body.protocol.obtained'));
        $this->assertCount(2, $transport->callCalls, 'A pending 200 body must not be cached.');
        $this->assertTrue((bool) $second['body']['obtained']);
    }

    public function test_a_successful_one_shot_body_is_cached_even_without_obtained(): void
    {
        $transport = new FakeSerproTransport(callResponses: [[
            'status' => 200,
            'body' => ['result' => ['situacao' => 'regular']],
        ]]);
        $poller = new ProtocolPoller($transport);

        $poller->poll($this->awaitingRun(), 'PROTO-1', [], 'access-token');
        $poller->poll($this->awaitingRun(), 'PROTO-1', [], 'access-token');

        $this->assertCount(1, $transport->callCalls);
    }

    public function test_the_cache_is_scoped_by_account_and_environment(): void
    {
        $transport = new FakeSerproTransport(callResponses: [
            ['status' => 200, 'body' => ['obtained' => true, 'dados' => []]],
            ['status' => 200, 'body' => ['obtained' => true, 'dados' => []]],
        ]);
        $poller = new ProtocolPoller($transport);

        $poller->poll($this->awaitingRun(accountId: 10), 'PROTO-1', [], 'access-token');
        $poller->poll($this->awaitingRun(accountId: 11), 'PROTO-1', [], 'access-token');

        $this->assertCount(2, $transport->callCalls);
    }

    public function test_a_blank_protocol_is_refused(): void
    {
        $transport = new FakeSerproTransport;
        $poller = new ProtocolPoller($transport);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('protocol_required');

        try {
            $poller->poll($this->awaitingRun(), '  ', [], 'access-token');
        } finally {
            $this->assertCount(0, $transport->callCalls);
        }
    }

    public function test_an_operation_forbidden_for_polling_is_refused_without_traffic(): void
    {
        $transport = new FakeSerproTransport;
        $poller = new ProtocolPoller($transport);

        try {
            $poller->poll($this->awaitingRun(operation: 'GERARDAS12'), 'PROTO-1', [], 'access-token');
            $this->fail('A forbidden polling operation must be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('protocol_polling_forbidden', $exception->getMessage());
        }

        $this->assertCount(0, $transport->callCalls);
    }

    private function awaitingRun(
        int $accountId = 10,
        string $operation = 'SOLICITARPROTOCOLO91',
        string $environment = 'homologacao',
    ): MonitoringRun {
        $run = new MonitoringRun;
        $run->forceFill([
            'account_id' => $accountId,
            'operation_code' => $operation,
            'environment' => $environment,
            'idempotency_key' => 'poll-key',
        ]);

        return $run;
    }
}
