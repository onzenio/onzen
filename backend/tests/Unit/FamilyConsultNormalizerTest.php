<?php

namespace Tests\Unit;

use App\Integrations\Serpro\FamilyConsultNormalizer;
use App\Models\MonitoringDefinition;
use PHPUnit\Framework\TestCase;

final class FamilyConsultNormalizerTest extends TestCase
{
    private FamilyConsultNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new FamilyConsultNormalizer;
    }

    public function test_pgdasd_index_fixture_extracts_essential_fields(): void
    {
        $normalized = $this->normalizer->normalize('CONSDECLARACAO13', $this->body('CONSDECLARACAO13'), 'fixture');

        $this->assertSame('CONSDECLARACAO13', $normalized['operation_code']);
        $this->assertSame('pgdasd', $normalized['family']);
        $this->assertTrue($normalized['normalized']);
        $this->assertSame('fixture', $normalized['provenance']);
        $this->assertSame('2026', $normalized['data']['ano_calendario']);

        $row = $normalized['data']['declaracoes'][0];
        $this->assertSame('202601', $row['periodo_apuracao']);
        $this->assertSame('Original', $row['tipo_operacao']);
        $this->assertSame('00000000000000000013', $row['numero_declaracao']);
        $this->assertSame('N', $row['malha']);
        $this->assertSame('85800000000000000013', $row['numero_das']);
        $this->assertFalse($row['das_pago']);
        $this->assertSame('2026-02-10T14:22:00Z', $row['data_hora_transmissao']);
        $this->assertSame('2026-02-10T14:25:00Z', $row['data_hora_emissao_das']);
    }

    public function test_fixture_envelope_and_metadata_are_normalized(): void
    {
        $normalized = $this->normalizer->normalize('  consdeclaracao13 ', $this->fixture('CONSDECLARACAO13'), 'fixture');

        $this->assertSame('CONSDECLARACAO13', $normalized['operation_code']);
        $this->assertSame('pgdasd', $normalized['family']);
        $this->assertTrue($normalized['normalized']);
        $this->assertSame('fixture', $normalized['provenance']);
        $this->assertSame(MonitoringDefinition::CATALOG_VERSION, $normalized['catalog_version']);
        $this->assertSame('2026', $normalized['data']['ano_calendario']);
        $this->assertSame('00000000000000000013', $normalized['data']['declaracoes'][0]['numero_declaracao']);
    }

    public function test_official_periodos_index_flattens_declaration_and_das_indices(): void
    {
        $normalized = $this->normalizer->normalize('CONSDECLARACAO13', [
            'dados' => [
                'anoCalendario' => '2026',
                'periodos' => [[
                    'periodoApuracao' => '202601',
                    'operacoes' => [
                        [
                            'tipoOperacao' => 'Original',
                            'indiceDeclaracao' => [
                                'numeroDeclaracao' => '00000000000000000013',
                                'dataHoraTransmissao' => '2026-02-10T14:22:00Z',
                                'malha' => 'N',
                            ],
                            'indiceDas' => null,
                        ],
                        [
                            'tipoOperacao' => 'Geração de DAS',
                            'indiceDeclaracao' => null,
                            'indiceDas' => [
                                'numeroDas' => '85800000000000000013',
                                'datahoraEmissaoDas' => '2026-02-10T14:25:00Z',
                                'dasPago' => false,
                            ],
                        ],
                    ],
                ]],
            ],
        ], 'serpro');

        $this->assertSame('pgdasd', $normalized['family']);
        $this->assertTrue($normalized['normalized']);
        $this->assertSame('2026', $normalized['data']['ano_calendario']);
        $this->assertCount(2, $normalized['data']['declaracoes']);

        $this->assertSame('202601', $normalized['data']['declaracoes'][0]['periodo_apuracao']);
        $this->assertSame('Original', $normalized['data']['declaracoes'][0]['tipo_operacao']);
        $this->assertSame('00000000000000000013', $normalized['data']['declaracoes'][0]['numero_declaracao']);
        $this->assertSame('N', $normalized['data']['declaracoes'][0]['malha']);
        $this->assertSame('2026-02-10T14:22:00Z', $normalized['data']['declaracoes'][0]['data_hora_transmissao']);
        $this->assertNull($normalized['data']['declaracoes'][0]['numero_das']);

        $this->assertSame('Geração de DAS', $normalized['data']['declaracoes'][1]['tipo_operacao']);
        $this->assertSame('85800000000000000013', $normalized['data']['declaracoes'][1]['numero_das']);
        $this->assertFalse($normalized['data']['declaracoes'][1]['das_pago']);
        $this->assertSame('2026-02-10T14:25:00Z', $normalized['data']['declaracoes'][1]['data_hora_emissao_das']);
        $this->assertNull($normalized['data']['declaracoes'][1]['numero_declaracao']);
    }

    public function test_json_string_periodos_and_nested_dados_envelope_are_unwrapped(): void
    {
        $normalized = $this->normalizer->normalize('CONSDECLARACAO13', [
            'dados' => json_encode([
                'status' => 'Sucesso-PGDASD',
                'codigo' => 'Sucesso-PGDASD',
                'dados' => [
                    'anoCalendario' => '2026',
                    'periodos' => json_encode([[
                        'periodoApuracao' => '202602',
                        'operacoes' => [[
                            'tipoOperacao' => 'Original',
                            'indiceDeclaracao' => [
                                'numeroDeclaracao' => '99',
                                'dataHoraTransmissao' => '2026-03-10T14:22:00Z',
                                'malha' => 'N',
                            ],
                        ]],
                    ]], JSON_UNESCAPED_UNICODE),
                ],
            ], JSON_UNESCAPED_UNICODE),
        ], 'serpro');

        $this->assertSame('2026', $normalized['data']['ano_calendario']);
        $this->assertCount(1, $normalized['data']['declaracoes']);
        $this->assertSame('202602', $normalized['data']['declaracoes'][0]['periodo_apuracao']);
        $this->assertSame('99', $normalized['data']['declaracoes'][0]['numero_declaracao']);
    }

    public function test_pgdasd_chain_children_expose_receipt_and_extract_fields(): void
    {
        $ultima = $this->normalizer->normalize('CONSULTIMADECREC14', $this->body('CONSULTIMADECREC14'), 'fixture');
        $this->assertSame('pgdasd', $ultima['family']);
        $this->assertSame('202601', $ultima['data']['periodo_apuracao']);
        $this->assertSame('00000000000000000013', $ultima['data']['numero_declaracao']);
        $this->assertSame('recibo.pdf', $ultima['data']['nome_arquivo_recibo']);
        $this->assertSame('declaracao.pdf', $ultima['data']['nome_arquivo_declaracao']);

        $recibo = $this->normalizer->normalize('CONSDECREC15', $this->body('CONSDECREC15'), 'fixture');
        $this->assertSame('pgdasd', $recibo['family']);
        $this->assertTrue($recibo['normalized']);
        $this->assertSame('202601', $recibo['data']['periodo_apuracao']);
        $this->assertSame('00000000000000000013', $recibo['data']['numero_declaracao']);
        $this->assertSame('recibo.pdf', $recibo['data']['nome_arquivo_recibo']);
        $this->assertSame('declaracao.pdf', $recibo['data']['nome_arquivo_declaracao']);
        $this->assertSame('maed.pdf', $recibo['data']['nome_arquivo_maed']);
        $this->assertArrayNotHasKey('declaracoes', $recibo['data']);

        $extrato = $this->normalizer->normalize('CONSEXTRATO16', $this->body('CONSEXTRATO16'), 'fixture');
        $this->assertSame('pgdasd', $extrato['family']);
        $this->assertSame('85800000000000000013', $extrato['data']['numero_das']);
        $this->assertSame('202601', $extrato['data']['periodo_apuracao']);
        $this->assertSame('extrato.pdf', $extrato['data']['nome_arquivo_extrato']);
        $this->assertNull($extrato['data']['nome_arquivo_recibo']);
    }

    public function test_pgdasd_missing_fields_stay_null_without_invention(): void
    {
        $normalized = $this->normalizer->normalize('CONSDECLARACAO13', [
            'dados' => ['declaracoes' => [['numeroDeclaracao' => '13']]],
        ], 'serpro');

        $this->assertTrue($normalized['normalized']);
        $this->assertNull($normalized['data']['ano_calendario']);

        $row = $normalized['data']['declaracoes'][0];
        $this->assertSame('13', $row['numero_declaracao']);
        $this->assertNull($row['periodo_apuracao']);
        $this->assertNull($row['tipo_operacao']);
        $this->assertNull($row['malha']);
        $this->assertNull($row['numero_das']);
        $this->assertNull($row['das_pago']);
        $this->assertNull($row['data_hora_transmissao']);
        $this->assertNull($row['data_hora_emissao_das']);
        $this->assertNull($row['nome_arquivo_recibo']);
        $this->assertNull($row['nome_arquivo_declaracao']);
        $this->assertNull($row['nome_arquivo_extrato']);
        $this->assertNull($row['nome_arquivo_maed']);
    }

    public function test_gerardas12_emission_normalizes_pdf_reference_and_neutral_filename(): void
    {
        $normalized = $this->normalizer->normalize('GERARDAS12', [
            'dados' => [[
                'pdf' => null,
                'pdf_storage_ref' => 'ref-das-202601',
                'pdf_hash_sha256' => 'hash-das',
                'periodoApuracao' => '202601',
                'dataConsolidacao' => '2026-02-11',
            ]],
        ], 'serpro');

        $this->assertSame('GERARDAS12', $normalized['operation_code']);
        $this->assertSame('pgdasd', $normalized['family']);
        $this->assertTrue($normalized['normalized']);
        $this->assertSame('202601', $normalized['data']['periodo_apuracao']);
        $this->assertSame('2026-02-11', $normalized['data']['data_consolidacao']);
        $this->assertNull($normalized['data']['pdf']);
        $this->assertSame('ref-das-202601', $normalized['data']['pdf_storage_ref']);
        $this->assertSame('hash-das', $normalized['data']['pdf_hash_sha256']);
        $this->assertSame('PGDASD-DAS-202601.pdf', $normalized['data']['nome_arquivo']);
    }

    public function test_gerardas12_without_period_does_not_invent_a_filename_period(): void
    {
        $normalized = $this->normalizer->normalize('GERARDAS12', [
            'dados' => ['pdf' => 'JVBERi0x', 'pdf_storage_ref' => 'ref-das', 'periodoApuracao' => null],
        ], 'fixture');

        $this->assertSame('pgdasd', $normalized['family']);
        $this->assertNull($normalized['data']['periodo_apuracao']);
        $this->assertSame('JVBERi0x', $normalized['data']['pdf']);
        $this->assertSame('PGDASD-DAS.pdf', $normalized['data']['nome_arquivo']);
    }

    public function test_regime_fixtures_extract_essential_fields(): void
    {
        $opcao = $this->normalizer->normalize('CONSULTAROPCAOREGIME103', $this->body('CONSULTAROPCAOREGIME103'), 'fixture');
        $this->assertSame('regime', $opcao['family']);
        $this->assertTrue($opcao['normalized']);
        $this->assertSame('2026', $opcao['data']['ano_calendario']);
        $this->assertSame('Caixa', $opcao['data']['opcao_regime']);
        $this->assertSame('2026-01-15', $opcao['data']['data_opcao']);
        $this->assertNull($opcao['data']['resolucao']);
        $this->assertNull($opcao['data']['situacao']);
        $this->assertNull($opcao['data']['anos_calendarios']);

        $resolucao = $this->normalizer->normalize('CONSULTARRESOLUCAO104', $this->body('CONSULTARRESOLUCAO104'), 'fixture');
        $this->assertSame('Regime de caixa homologado', $resolucao['data']['resolucao']);
        $this->assertSame('ativa', $resolucao['data']['situacao']);
        $this->assertNull($resolucao['data']['opcao_regime']);

        $anos = $this->normalizer->normalize('CONSULTARANOSCALENDARIOS102', $this->body('CONSULTARANOSCALENDARIOS102'), 'fixture');
        $this->assertNull($anos['data']['ano_calendario']);
        $this->assertNull($anos['data']['opcao_regime']);
        $this->assertCount(2, $anos['data']['anos_calendarios']);
        $this->assertSame('2026', $anos['data']['anos_calendarios'][1]['anoCalendario']);
    }

    public function test_defis_summary_and_declaration_detail_are_distinguished(): void
    {
        $summary = $this->normalizer->normalize('CONSDECLARACAO142', $this->body('CONSDECLARACAO142'), 'fixture');
        $this->assertSame('defis', $summary['family']);
        $this->assertTrue($summary['normalized']);
        $this->assertArrayNotHasKey('numero_declaracao', $summary['data']);
        $this->assertCount(1, $summary['data']['declaracoes']);
        $this->assertSame('2025', $summary['data']['declaracoes'][0]['ano_calendario']);
        $this->assertSame('DEFIS000000000000142', $summary['data']['declaracoes'][0]['numero_declaracao']);
        $this->assertSame('Original', $summary['data']['declaracoes'][0]['tipo_operacao']);
        $this->assertSame('2026-03-20T11:00:00Z', $summary['data']['declaracoes'][0]['data_hora_transmissao']);

        $detail = $this->normalizer->normalize('CONSDECREC144', $this->body('CONSDECREC144'), 'fixture');
        $this->assertSame('defis', $detail['family']);
        $this->assertTrue($detail['normalized']);
        $this->assertArrayNotHasKey('declaracoes', $detail['data']);
        $this->assertSame('2025', $detail['data']['ano_calendario']);
        $this->assertSame('DEFIS000000000000142', $detail['data']['numero_declaracao']);
        $this->assertSame('recibo-defis.pdf', $detail['data']['nome_arquivo_recibo']);
        $this->assertSame('declaracao-defis.pdf', $detail['data']['nome_arquivo_declaracao']);
        $this->assertNull($detail['data']['tipo_operacao'] ?? null);
    }

    public function test_mei_fixtures_extract_essential_fields(): void
    {
        $dados = $this->normalizer->normalize('DADOSCCMEI122', $this->body('DADOSCCMEI122'), 'fixture');
        $this->assertSame('mei', $dados['family']);
        $this->assertTrue($dados['normalized']);
        $this->assertSame('ATIVA', $dados['data']['situacao']);
        $this->assertSame('00000000000191', $dados['data']['cnpj']);
        $this->assertSame('MEI Fixture', $dados['data']['nome_empresarial']);
        $this->assertSame('2020-03-01', $dados['data']['data_inicio']);
        $this->assertNull($dados['data']['valor_inscrito']);
        $this->assertNull($dados['data']['vinculos']);

        $cadastral = $this->normalizer->normalize('CCMEISITCADASTRAL123', $this->body('CCMEISITCADASTRAL123'), 'fixture');
        $this->assertSame('mei', $cadastral['family']);
        $this->assertCount(1, $cadastral['data']['vinculos']);
        $this->assertSame('00000000000191', $cadastral['data']['vinculos'][0]['cnpj']);
        $this->assertSame('ATIVA', $cadastral['data']['vinculos'][0]['situacaoCadastral']);
        $this->assertNull($cadastral['data']['situacao']);

        $divida = $this->normalizer->normalize('DIVIDAATIVA24', $this->body('DIVIDAATIVA24'), 'fixture');
        $this->assertSame('sem_divida', $divida['data']['situacao']);
        $this->assertSame(0, $divida['data']['valor_inscrito']);
        $this->assertNull($divida['data']['cnpj']);
        $this->assertNull($divida['data']['nome_empresarial']);
    }

    public function test_dctfweb_and_mit_fixtures_extract_essential_fields(): void
    {
        $recibo = $this->normalizer->normalize('CONSRECIBO32', $this->body('CONSRECIBO32'), 'fixture');
        $this->assertSame('dctfweb', $recibo['family']);
        $this->assertTrue($recibo['normalized']);
        $this->assertSame('2026-01', $recibo['data']['periodo_apuracao']);
        $this->assertSame('DCTF32-0001', $recibo['data']['numero_recibo']);
        $this->assertSame('recibo-dctfweb.pdf', $recibo['data']['nome_arquivo_recibo']);
        $this->assertNull($recibo['data']['situacao']);
        $this->assertNull($recibo['data']['valor_apurado']);
        $this->assertNull($recibo['data']['apuracoes']);

        $completa = $this->normalizer->normalize('CONSDECCOMPLETA33', $this->body('CONSDECCOMPLETA33'), 'fixture');
        $this->assertSame('entregue', $completa['data']['situacao']);
        $this->assertSame('dctfweb.pdf', $completa['data']['nome_arquivo_declaracao']);
        $this->assertNull($completa['data']['numero_recibo']);

        $xml = $this->normalizer->normalize('CONSXMLDECLARACAO38', $this->body('CONSXMLDECLARACAO38'), 'fixture');
        $this->assertSame('2026-01', $xml['data']['periodo_apuracao']);
        $this->assertSame('dctfweb.xml', $xml['data']['nome_arquivo_xml']);
        $this->assertNull($xml['data']['nome_arquivo_recibo']);

        $encerramento = $this->normalizer->normalize('SITUACAOENC315', $this->body('SITUACAOENC315'), 'fixture');
        $this->assertSame('dctfweb', $encerramento['family']);
        $this->assertSame('2026-01', $encerramento['data']['periodo_apuracao']);
        $this->assertSame('encerrada', $encerramento['data']['situacao']);

        $apuracao = $this->normalizer->normalize('CONSAPURACAO316', $this->body('CONSAPURACAO316'), 'fixture');
        $this->assertSame(150.25, $apuracao['data']['valor_apurado']);
        $this->assertSame('apurada', $apuracao['data']['situacao']);

        $lista = $this->normalizer->normalize('LISTAAPURACOES317', $this->body('LISTAAPURACOES317'), 'fixture');
        $this->assertCount(2, $lista['data']['apuracoes']);
        $this->assertSame('2026-01', $lista['data']['apuracoes'][0]['periodoApuracao']);
    }

    public function test_sitfis_fixtures_extract_essential_fields(): void
    {
        $protocolo = $this->normalizer->normalize('SOLICITARPROTOCOLO91', $this->body('SOLICITARPROTOCOLO91'), 'fixture');
        $this->assertSame('sitfis', $protocolo['family']);
        $this->assertTrue($protocolo['normalized']);
        $this->assertSame('SITFIS-PROTO-91', $protocolo['data']['protocolo']);
        $this->assertNull($protocolo['data']['situacao']);
        $this->assertNull($protocolo['data']['nome_arquivo_relatorio']);

        $relatorio = $this->normalizer->normalize('RELATORIOSITFIS92', $this->body('RELATORIOSITFIS92'), 'fixture');
        $this->assertSame('SITFIS-PROTO-91', $relatorio['data']['protocolo']);
        $this->assertSame('regular', $relatorio['data']['situacao']);
        $this->assertSame('sitfis.pdf', $relatorio['data']['nome_arquivo_relatorio']);
    }

    public function test_caixa_postal_and_dte_fixtures_extract_essential_fields(): void
    {
        $lista = $this->normalizer->normalize('MSGCONTRIBUINTE61', $this->body('MSGCONTRIBUINTE61'), 'fixture');
        $this->assertSame('caixa_postal', $lista['family']);
        $this->assertTrue($lista['normalized']);
        $this->assertCount(1, $lista['data']['mensagens']);
        $this->assertSame('MSG-61-001', $lista['data']['mensagens'][0]['id_mensagem']);
        $this->assertSame('Intimação de malha', $lista['data']['mensagens'][0]['assunto']);
        $this->assertFalse($lista['data']['mensagens'][0]['lida']);
        $this->assertSame('2026-02-18T09:00:00Z', $lista['data']['mensagens'][0]['data_hora']);
        $this->assertSame('RFB', $lista['data']['mensagens'][0]['origem']);

        $detalhe = $this->normalizer->normalize('MSGDETALHAMENTO62', $this->body('MSGDETALHAMENTO62'), 'fixture');
        $this->assertSame('MSG-61-001', $detalhe['data']['id_mensagem']);
        $this->assertSame('Intimação de malha', $detalhe['data']['assunto']);
        $this->assertFalse($detalhe['data']['lida']);
        $this->assertSame('2026-02-18T09:00:00Z', $detalhe['data']['data_hora']);
        $this->assertNull($detalhe['data']['possui_novas_mensagens']);
        $this->assertNull($detalhe['data']['quantidade']);
        $this->assertNull($detalhe['data']['adesao_caixa_postal_sn']);
        $this->assertNull($detalhe['data']['situacao']);
        $this->assertArrayNotHasKey('conteudo', $detalhe['data']);

        $indicador = $this->normalizer->normalize('INNOVAMSG63', $this->body('INNOVAMSG63'), 'fixture');
        $this->assertTrue($indicador['data']['possui_novas_mensagens']);
        $this->assertSame(1, $indicador['data']['quantidade']);
        $this->assertNull($indicador['data']['id_mensagem']);

        $dte = $this->normalizer->normalize('CONSULTASITUACAODTE111', $this->body('CONSULTASITUACAODTE111'), 'fixture');
        $this->assertSame('caixa_postal', $dte['family']);
        $this->assertTrue($dte['data']['adesao_caixa_postal_sn']);
        $this->assertTrue($dte['data']['adesao_ecac']);
        $this->assertSame('aderente', $dte['data']['situacao']);
    }

    public function test_family_without_normalizer_keeps_raw_result_flagged(): void
    {
        $raw = ['codigoBarras' => '001', 'valor' => 12.5, 'semNormalizador' => true];
        $normalized = $this->normalizer->normalize('PAGAMENTOS71', ['dados' => $raw], 'serpro');

        $this->assertSame('PAGAMENTOS71', $normalized['operation_code']);
        $this->assertSame('pagamentos', $normalized['family']);
        $this->assertFalse($normalized['normalized']);
        $this->assertSame($raw, $normalized['data']);
        $this->assertSame(array_keys($raw), array_keys($normalized['data']));

        $parcelment = $this->normalizer->normalize('PEDIDOSPARC163', ['dados' => ['pedidos' => [['numero' => '1']]]], 'fixture');
        $this->assertSame('parcelment', $parcelment['family']);
        $this->assertFalse($parcelment['normalized']);
        $this->assertSame(['pedidos' => [['numero' => '1']]], $parcelment['data']);
    }

    public function test_unknown_operation_is_flagged_generic_without_normalizer(): void
    {
        $normalized = $this->normalizer->normalize('UNKNOWN999', ['dados' => ['x' => 1]], 'fixture');

        $this->assertSame('UNKNOWN999', $normalized['operation_code']);
        $this->assertSame('generic', $normalized['family']);
        $this->assertFalse($normalized['normalized']);
        $this->assertSame(['x' => 1], $normalized['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $key): array
    {
        $path = dirname(__DIR__, 2).'/resources/fixtures/serpro/consultar/'.$key.'.json';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, $key);

        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded, $key);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $key): array
    {
        $fixture = $this->fixture($key);
        $this->assertIsArray($fixture['body'] ?? null, $key);

        return $fixture['body'];
    }
}
