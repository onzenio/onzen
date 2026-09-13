<?php

namespace Tests\Unit;

use App\Integrations\Serpro\SerproEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SerproEnvelopeTest extends TestCase
{
    public function test_party_keeps_only_digits_and_infers_type_from_length(): void
    {
        $this->assertSame(
            ['numero' => '12345678000195', 'tipo' => 2],
            SerproEnvelope::partyFor('12.345.678/0001-95'),
        );
        $this->assertSame(
            ['numero' => '12345678901', 'tipo' => 1],
            SerproEnvelope::partyFor('123.456.789-01'),
        );
        $this->assertSame(1, SerproEnvelope::partyFor('12345678901')['tipo']);
        $this->assertSame(2, SerproEnvelope::partyFor('12345678000195')['tipo']);
    }

    public function test_explicit_person_type_wins_over_document_length(): void
    {
        $this->assertSame(2, SerproEnvelope::partyFor('12345678901', 'PJ')['tipo']);
        $this->assertSame(1, SerproEnvelope::partyFor('12345678000195', 'PF')['tipo']);
        $this->assertSame(1, SerproEnvelope::partyFor('12345678000195', 'pf')['tipo']);
        $this->assertSame(1, SerproEnvelope::partyFor('12345678000195', 1)['tipo']);
        $this->assertSame(2, SerproEnvelope::partyFor('12345678901', 2)['tipo']);
        $this->assertSame(2, SerproEnvelope::partyFor('12345678901', '2')['tipo']);
    }

    public function test_invalid_explicit_person_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('serpro_envelope_person_type_invalid');

        SerproEnvelope::partyFor('12345678901', 'MEI');
    }

    public function test_envelope_carries_three_parties_and_catalog_routing(): void
    {
        $envelope = SerproEnvelope::make(
            ['numero' => '65.396.736/0001-76'],
            ['numero' => '11.222.333/0001-81'],
            ['numero' => '123.456.789-01'],
            'CONSDECLARACAO13',
            ['competencia' => '202601'],
        );

        $this->assertSame(['numero' => '65396736000176', 'tipo' => 2], $envelope['contratante']);
        $this->assertSame(['numero' => '11222333000181', 'tipo' => 2], $envelope['autorPedidoDados']);
        $this->assertSame(['numero' => '12345678901', 'tipo' => 1], $envelope['contribuinte']);
        $this->assertSame('PGDASD', $envelope['pedidoDados']['idSistema']);
        $this->assertSame('CONSDECLARACAO13', $envelope['pedidoDados']['idServico']);
        $this->assertSame('1', $envelope['pedidoDados']['versaoSistema']);
        $this->assertSame(['competencia' => '202601'], json_decode($envelope['pedidoDados']['dados'], true));
    }

    public function test_dados_are_serialized_as_string_on_the_wire(): void
    {
        $envelope = SerproEnvelope::make(
            ['numero' => '65396736000176'],
            ['numero' => '11222333000181'],
            ['numero' => '11222333000181'],
            'ENVIOXMLASSINADO81',
            ['xml' => 'PENJ2YWxpZGFkYWRl'],
        );

        $this->assertSame('AUTENTICAPROCURADOR', $envelope['pedidoDados']['idSistema']);
        $this->assertSame('1.0', $envelope['pedidoDados']['versaoSistema']);
        $this->assertIsString($envelope['pedidoDados']['dados']);
        $this->assertSame(['xml' => 'PENJ2YWxpZGFkYWRl'], json_decode($envelope['pedidoDados']['dados'], true));
    }

    public function test_obterprocuracao_dados_default_shape(): void
    {
        $dados = SerproEnvelope::dadosFor('OBTERPROCURACAO41', [
            'outorgante' => '12345678000195',
            'tipoOutorgante' => '2',
            'outorgado' => '11222333000181',
            'tipoOutorgado' => '2',
            'unexpected' => 'ignored',
        ]);

        $this->assertSame([
            'outorgante' => '12345678000195',
            'tipoOutorgante' => '2',
            'outorgado' => '11222333000181',
            'tipoOutorgado' => '2',
        ], $dados);
    }

    public function test_envioxmlassinado_dados_default_shape(): void
    {
        $this->assertSame(
            ['xml' => 'PGJhc2U2ND48L2Jhc2U2ND4='],
            SerproEnvelope::dadosFor('ENVIOXMLASSINADO81', ['xml' => 'PGJhc2U2ND48L2Jhc2U2ND4=', 'ignored' => true]),
        );
    }

    public function test_gerardas_dados_default_shape(): void
    {
        $this->assertSame(
            ['periodo_apuracao' => '202508', 'data_consolidacao' => '2025-09-10'],
            SerproEnvelope::dadosFor('GERARDAS12', [
                'data_consolidacao' => '2025-09-10',
                'periodo_apuracao' => '202508',
                'ignored' => 'x',
            ]),
        );
    }

    public function test_pgdas_chain_dados_defaults(): void
    {
        $this->assertSame(
            ['numeroDeclaracao' => 'DEC-1', 'periodoApuracao' => '202508'],
            SerproEnvelope::dadosFor('CONSDECREC15', [
                'numeroDeclaracao' => 'DEC-1',
                'periodoApuracao' => '202508',
                'extra' => 'ignored',
            ]),
        );
        $this->assertSame(
            ['anoCalendario' => '2025'],
            SerproEnvelope::dadosFor('CONSULTIMADECREC14', ['anoCalendario' => '2025', 'extra' => 'ignored']),
        );
        $this->assertSame(
            ['numeroDas' => 'DAS-1'],
            SerproEnvelope::dadosFor('CONSEXTRATO16', ['numeroDas' => 'DAS-1', 'extra' => 'ignored']),
        );
    }

    public function test_other_operations_pass_parameters_through_unchanged(): void
    {
        $parameters = ['competencia' => '202601', 'something' => ['nested' => true]];

        $this->assertSame($parameters, SerproEnvelope::dadosFor('CONSDECLARACAO13', $parameters));
        $this->assertSame($parameters, SerproEnvelope::dadosFor('PAGAMENTOS71', $parameters));
        $this->assertSame([], SerproEnvelope::dadosFor('PAGAMENTOS71'));
    }

    public function test_term_envelope_falls_back_to_author_as_contribuinte(): void
    {
        $envelope = SerproEnvelope::make(
            ['numero' => '65396736000176'],
            ['numero' => '11222333000181'],
            ['numero' => ''],
            'ENVIOXMLASSINADO81',
            ['xml' => 'PGJhc2U2ND48L2Jhc2U2ND4='],
        );

        $this->assertSame(['numero' => '11222333000181', 'tipo' => 2], $envelope['contribuinte']);
    }

    public function test_envelope_rejects_unknown_operation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported SERPRO operation: UNKNOWN999');

        SerproEnvelope::make(
            ['numero' => '65396736000176'],
            ['numero' => '11222333000181'],
            ['numero' => '12345678000195'],
            'UNKNOWN999',
        );
    }
}
