<?php

namespace Tests\Unit;

use App\Integrations\Serpro\SerproEnvelope;
use App\Integrations\Serpro\SerproOperationRouter;
use PHPUnit\Framework\TestCase;

class SerproEnvelopeTest extends TestCase
{
    public function test_monta_envelope_de_3_partes(): void
    {
        $envelope = SerproEnvelope::build(
            'consultar-sitfis',
            '12345678000195',
            '12345678901',
            '12345678000195',
            ['exercicio' => '2025'],
        );

        $this->assertSame('PJ', $envelope['contratante']['tipo']);
        $this->assertSame('12345678000195', $envelope['contratante']['numero']);
        $this->assertSame('PF', $envelope['autorPedidoDados']['tipo']);
        $this->assertSame('PJ', $envelope['pedido']['contribuinte']['tipo']);
        $this->assertSame('consultar-sitfis', $envelope['pedido']['operacao']);
        $this->assertSame('2025', $envelope['pedido']['exercicio']);
    }

    public function test_tipos_pf_pj_e_documento_invalido(): void
    {
        $this->assertSame('PF', SerproEnvelope::personType('123.456.789-01'));
        $this->assertSame('PJ', SerproEnvelope::personType('12.345.678/0001-95'));

        $this->expectException(\InvalidArgumentException::class);
        SerproEnvelope::personType('123');
    }

    public function test_roteamento_por_tipo_de_operacao(): void
    {
        $this->assertSame('consultar/consultar-sitfis', SerproOperationRouter::pathFor('consultar-sitfis'));
        $this->assertSame('acoes/emitir-das-pgdasd', SerproOperationRouter::pathFor('emitir-das-pgdasd'));
        $this->assertFalse(SerproOperationRouter::isWrite('consultar-sitfis'));
        $this->assertTrue(SerproOperationRouter::isWrite('emitir-das-pgdasd'));

        $this->expectException(\InvalidArgumentException::class);
        SerproOperationRouter::pathFor('operacao-desconhecida');
    }
}
