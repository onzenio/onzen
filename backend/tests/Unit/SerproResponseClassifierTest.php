<?php

namespace Tests\Unit;

use App\Integrations\Serpro\SerproResponseClassifier;
use PHPUnit\Framework\TestCase;

class SerproResponseClassifierTest extends TestCase
{
    public function test_429_tem_retry_com_backoff(): void
    {
        $first = SerproResponseClassifier::classify(429, [], 1);
        $second = SerproResponseClassifier::classify(429, [], 2);

        $this->assertSame('transient', $first['outcome']);
        $this->assertSame(60, $first['backoff_seconds']);
        $this->assertSame(120, $second['backoff_seconds']);
    }

    public function test_timeout_e_5xx_sao_transitorios(): void
    {
        $this->assertSame('transient', SerproResponseClassifier::classify(null, [])['outcome']);
        $this->assertSame('transient', SerproResponseClassifier::classify(500, [])['outcome']);
        $this->assertSame('transient', SerproResponseClassifier::classify(503, [])['outcome']);
    }

    public function test_protocolo_pendente_e_pollado(): void
    {
        $byStatus = SerproResponseClassifier::classify(202, ['protocolo' => 'P1']);
        $byPayload = SerproResponseClassifier::classify(200, ['protocolo' => 'P1', 'situacao' => 'pendente']);

        $this->assertSame('protocol', $byStatus['outcome']);
        $this->assertSame('protocol', $byPayload['outcome']);
        $this->assertNotNull($byPayload['backoff_seconds']);
    }

    public function test_rejeicao_definitiva_nao_repete(): void
    {
        $this->assertSame('fatal', SerproResponseClassifier::classify(422, [])['outcome']);
        $this->assertSame('fatal', SerproResponseClassifier::classify(400, ['codigo_erro' => 'E1'])['outcome']);
        $this->assertSame('fatal', SerproResponseClassifier::classify(200, ['rejeicao_definitiva' => true])['outcome']);
        $this->assertNull(SerproResponseClassifier::classify(422, [])['backoff_seconds']);
    }

    public function test_sucesso(): void
    {
        $this->assertSame('success', SerproResponseClassifier::classify(200, ['ok' => true])['outcome']);
    }
}
