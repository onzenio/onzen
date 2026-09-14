<?php

namespace Tests\Unit;

use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\ConsultOperationResolver;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\MonitoringDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConsultCatalogTest extends TestCase
{
    public function test_each_available_definition_maps_to_official_consult_operations(): void
    {
        $expected = [
            'pgdas-declaracoes' => ['CONSDECLARACAO13', 'CONSULTIMADECREC14', 'CONSDECREC15', 'CONSEXTRATO16'],
            'regime-apuracao' => ['CONSULTARANOSCALENDARIOS102', 'CONSULTAROPCAOREGIME103', 'CONSULTARRESOLUCAO104'],
            'defis' => ['CONSDECLARACAO142', 'CONSULTIMADECREC143', 'CONSDECREC144'],
            'situacao-mei' => ['DIVIDAATIVA24', 'DADOSCCMEI122', 'CCMEISITCADASTRAL123'],
            'dctfweb' => ['CONSRECIBO32', 'CONSDECCOMPLETA33', 'CONSXMLDECLARACAO38'],
            'mit' => ['SITUACAOENC315', 'CONSAPURACAO316', 'LISTAAPURACOES317'],
            'situacao-fiscal' => ['SOLICITARPROTOCOLO91', 'RELATORIOSITFIS92'],
            'caixa-postal' => ['MSGCONTRIBUINTE61', 'MSGDETALHAMENTO62', 'INNOVAMSG63'],
            'dte' => ['CONSULTASITUACAODTE111'],
            'pagamentos' => ['PAGAMENTOS71', 'CONTACONSDOCARRPG73'],
        ];

        foreach ($expected as $definitionId => $operations) {
            $this->assertTrue(ConsultCatalog::isConsultDefinition($definitionId), $definitionId);
            $this->assertSame($operations, ConsultCatalog::operationsFor($definitionId), $definitionId);
            $this->assertSame($operations[0], ConsultCatalog::indexOperation($definitionId), $definitionId);
        }
    }

    public function test_parcelment_modalities_carry_consult_and_explicit_gerardas_operations(): void
    {
        $expected = [
            'parcsn' => ['PEDIDOSPARC163', 'OBTERPARC164', 'PARCELASPARAGERAR162', 'DETPAGTOPARC165', 'GERARDAS161'],
            'parcsn-esp' => ['PEDIDOSPARC173', 'OBTERPARC174', 'PARCELASPARAGERAR172', 'DETPAGTOPARC175', 'GERARDAS171'],
            'pertsn' => ['PEDIDOSPARC183', 'OBTERPARC184', 'PARCELASPARAGERAR182', 'DETPAGTOPARC185', 'GERARDAS181'],
            'relpsn' => ['PEDIDOSPARC193', 'OBTERPARC194', 'PARCELASPARAGERAR192', 'DETPAGTOPARC195', 'GERARDAS191'],
            'parcmei' => ['PEDIDOSPARC203', 'OBTERPARC204', 'PARCELASPARAGERAR202', 'DETPAGTOPARC205', 'GERARDAS201'],
            'parcmei-esp' => ['PEDIDOSPARC213', 'OBTERPARC214', 'PARCELASPARAGERAR212', 'DETPAGTOPARC215', 'GERARDAS211'],
            'pertmei' => ['PEDIDOSPARC223', 'OBTERPARC224', 'PARCELASPARAGERAR222', 'DETPAGTOPARC225', 'GERARDAS221'],
            'relpmei' => ['PEDIDOSPARC233', 'OBTERPARC234', 'PARCELASPARAGERAR232', 'DETPAGTOPARC235', 'GERARDAS231'],
        ];

        $this->assertCount(8, ConsultCatalog::PARCELMENT_OPERATIONS);
        $this->assertCount(8, ConsultCatalog::PARCELMENT_MODALITIES);
        $this->assertSame(array_keys($expected), array_keys(ConsultCatalog::PARCELMENT_OPERATIONS));

        foreach ($expected as $modality => $operations) {
            $this->assertSame($operations, ConsultCatalog::operationsFor($modality), $modality);
            $this->assertSame($operations[0], ConsultCatalog::indexOperation($modality), $modality);
            $this->assertTrue(ConsultCatalog::isParcelmentConsult($operations[0]), $operations[0]);
            $this->assertFalse(ConsultCatalog::isForbiddenPolling($operations[0]), $operations[0]);
            $this->assertTrue(ConsultCatalog::isExplicitFiscalAction(end($operations)));
            $this->assertSame(strtoupper($modality), ConsultCatalog::parcelmentModality((string) end($operations)), $modality);
            $this->assertSame(strtoupper($modality), ConsultCatalog::parcelmentModality($operations[0]), $modality);
            $this->assertSame((string) end($operations), ConsultCatalog::gerardasForModality($modality), $modality);
        }
    }

    public function test_prospecting_definitions_have_no_executable_operations(): void
    {
        $this->assertSame(['parc-paex', 'parc-sipade'], ConsultCatalog::PROSPECCAO_DEFINITIONS);

        foreach (ConsultCatalog::PROSPECCAO_DEFINITIONS as $definitionId) {
            $this->assertTrue(ConsultCatalog::isProspeccao($definitionId), $definitionId);
            $this->assertFalse(ConsultCatalog::isConsultDefinition($definitionId), $definitionId);
            $this->assertNull(ConsultCatalog::operationsFor($definitionId), $definitionId);
            $this->assertNull(ConsultCatalog::indexOperation($definitionId), $definitionId);
        }
    }

    public function test_forbidden_polling_covers_declaration_transmission_and_emission_families(): void
    {
        $forbidden = [
            'GERARDAS12',
            'GERARDASCOBRANCA17',
            'GERARDASPROCESSO18',
            'GERARDASAVULSO19',
            'GERARDASPDF21',
            'GERARDASCODBARRA22',
            'TRANSDECLARACAO11',
            'TRANSDECLARACAO141',
            'TRANSDECLARACAO151',
            'TRANSDECLARACAO310',
            'EFETUAROPCAOREGIME101',
            'EMITIRCCMEI121',
            'GERARGUIA31',
            'GERARGUIAMAED36',
            'GERARGUIACOMABATIMENTO311',
            'GERARGUIAANDAMENTO313',
            'ENCAPURACAO314',
            'E0301',
        ];

        foreach ($forbidden as $code) {
            $this->assertTrue(ConsultCatalog::isForbiddenPolling($code), $code);
            $this->assertTrue(ConsultCatalog::isExplicitFiscalAction($code), $code);
        }

        foreach (ConsultCatalog::PARCELMENT_OPERATIONS as $operations) {
            $this->assertTrue(ConsultCatalog::isForbiddenPolling((string) end($operations)));
        }
        $this->assertTrue(ConsultCatalog::isForbiddenPolling('GERARDAS'));
        $this->assertTrue(ConsultCatalog::isForbiddenPolling('GERARDAS161'));

        $allowed = ['CONSDECLARACAO13', 'CONSULTIMADECREC14', 'SOLICITARPROTOCOLO91', 'RELATORIOSITFIS92', 'PEDIDOSPARC163', 'OBTERPARC164', 'PAGAMENTOS71', 'CONTACONSDOCARRPG73'];
        foreach ($allowed as $code) {
            $this->assertFalse(ConsultCatalog::isForbiddenPolling($code), $code);
            $this->assertFalse(ConsultCatalog::isExplicitFiscalAction($code), $code);
        }

        $this->assertTrue(ConsultCatalog::isExplicitEmission('GERARDAS12'));
        $this->assertFalse(ConsultCatalog::isExplicitEmission('GERARDAS161'));
        $this->assertFalse(ConsultCatalog::isExplicitEmission('TRANSDECLARACAO11'));
    }

    public function test_fixture_keys_group_parcelment_codes_by_operation_prefix(): void
    {
        $this->assertSame('PEDIDOSPARC', ConsultCatalog::fixtureKey('PEDIDOSPARC163'));
        $this->assertSame('OBTERPARC', ConsultCatalog::fixtureKey('OBTERPARC164'));
        $this->assertSame('PARCELASPARAGERAR', ConsultCatalog::fixtureKey('PARCELASPARAGERAR162'));
        $this->assertSame('DETPAGTOPARC', ConsultCatalog::fixtureKey('DETPAGTOPARC165'));
        $this->assertSame('CONSDECLARACAO13', ConsultCatalog::fixtureKey(' consdeclaracao13 '));
        $this->assertSame('GERARDAS161', ConsultCatalog::fixtureKey('GERARDAS161'));
    }

    public function test_family_map_covers_every_consult_family(): void
    {
        $this->assertSame('pgdasd', ConsultCatalog::familyFor('CONSDECLARACAO13'));
        $this->assertSame('regime', ConsultCatalog::familyFor('CONSULTAROPCAOREGIME103'));
        $this->assertSame('defis', ConsultCatalog::familyFor('CONSDECREC144'));
        $this->assertSame('mei', ConsultCatalog::familyFor('DADOSCCMEI122'));
        $this->assertSame('dctfweb', ConsultCatalog::familyFor('CONSRECIBO32'));
        $this->assertSame('dctfweb', ConsultCatalog::familyFor('CONSAPURACAO316'));
        $this->assertSame('sitfis', ConsultCatalog::familyFor('SOLICITARPROTOCOLO91'));
        $this->assertSame('sitfis', ConsultCatalog::familyFor('RELATORIOSITFIS92'));
        $this->assertSame('caixa_postal', ConsultCatalog::familyFor('MSGCONTRIBUINTE61'));
        $this->assertSame('caixa_postal', ConsultCatalog::familyFor('CONSULTASITUACAODTE111'));
        $this->assertSame('pagamentos', ConsultCatalog::familyFor('PAGAMENTOS71'));
        $this->assertSame('pagamentos', ConsultCatalog::familyFor('CONTACONSDOCARRPG73'));
        $this->assertSame('parcelment', ConsultCatalog::familyFor('PEDIDOSPARC163'));
        $this->assertNull(ConsultCatalog::familyFor('UNKNOWN999'));
    }

    public function test_module_map_lists_definitions_per_tab(): void
    {
        $this->assertSame(['pgdas-declaracoes', 'regime-apuracao', 'defis', 'situacao-mei'], ConsultCatalog::definitionIdsForModule('simples'));
        $this->assertSame(['dctfweb', 'mit'], ConsultCatalog::definitionIdsForModule('declaracoes-dctfweb'));
        $this->assertSame(['pgdas-declaracoes'], ConsultCatalog::definitionIdsForModule('declaracoes-pgdas'));
        $this->assertSame(['situacao-fiscal'], ConsultCatalog::definitionIdsForModule('situacao-fiscal'));
        $this->assertSame(['pagamentos'], ConsultCatalog::definitionIdsForModule('situacao-comprovantes'));
        $this->assertSame(['caixa-postal', 'dte'], ConsultCatalog::definitionIdsForModule('caixa-postal'));
        $this->assertSame(['caixa-postal', 'dte'], ConsultCatalog::definitionIdsForModule('caixas-postais'));
        $this->assertSame(['parcsn', 'pertsn', 'relpsn'], ConsultCatalog::definitionIdsForModule('parcelamentos-simples-nacional'));
        $this->assertSame(['parc-paex', 'parc-sipade'], ConsultCatalog::definitionIdsForModule('parcelamentos-pgfn'));
        $this->assertSame([], ConsultCatalog::definitionIdsForModule('unknown-tab'));
    }

    public function test_person_types_are_declared_only_where_the_official_table_requires(): void
    {
        $this->assertSame(['PJ'], ConsultCatalog::personTypesFor('pgdas-declaracoes'));
        $this->assertSame(['PJ'], ConsultCatalog::personTypesFor('regime-apuracao'));
        $this->assertSame(['PJ'], ConsultCatalog::personTypesFor('defis'));
        $this->assertSame(['PF', 'PJ'], ConsultCatalog::personTypesFor('situacao-mei'));
        $this->assertNull(ConsultCatalog::personTypesFor('dctfweb'));
    }

    public function test_procuration_allowlist_accepts_official_codes_and_rejects_unknown(): void
    {
        $official = ['00146', '00103', '00060', '00050', '00002', '00004', '00006', '00051', '00229', '00076', '00188', '00125', '00149', '10011', '00210', '10036', '00134', '00133', '00152', '10012', '00209', '10035'];

        $this->assertSame($official, ProcurationCatalog::ALLOWLIST);
        $this->assertSame($official, ProcurationCatalog::allowlist());

        foreach ($official as $code) {
            $this->assertTrue(ProcurationCatalog::isAllowedCode($code), $code);
        }

        foreach (['', '99999', '00001', '146', 'abcde'] as $code) {
            $this->assertFalse(ProcurationCatalog::isAllowedCode($code), $code);
        }

        $this->assertTrue(ProcurationCatalog::isAllowedCode(' 00146 '));
    }

    public function test_definition_procuration_codes_match_the_official_table(): void
    {
        $this->assertSame(['00146'], ProcurationCatalog::codesForDefinition('pgdas-declaracoes'));
        $this->assertSame(['00146'], ProcurationCatalog::codesForDefinition('defis'));
        $this->assertSame(['00060'], ProcurationCatalog::codesForDefinition('regime-apuracao'));
        $this->assertSame(['00103'], ProcurationCatalog::codesForDefinition('dctfweb'));
        $this->assertSame(['00103'], ProcurationCatalog::codesForDefinition('mit'));
        $this->assertSame(['00002'], ProcurationCatalog::codesForDefinition('situacao-fiscal'));
        $this->assertSame(['00006'], ProcurationCatalog::codesForDefinition('caixa-postal'));
        $this->assertSame(['00050'], ProcurationCatalog::codesForDefinition('dte'));
        $this->assertSame(['00004'], ProcurationCatalog::codesForDefinition('pagamentos'));
        $this->assertSame([], ProcurationCatalog::codesForDefinition('situacao-mei'));
        $this->assertSame(['00076', '00188'], ProcurationCatalog::codesForDefinition('parcsn'));
        $this->assertSame(['00125'], ProcurationCatalog::codesForDefinition('parcsn-esp'));
        $this->assertSame(['00149', '10011'], ProcurationCatalog::codesForDefinition('pertsn'));
        $this->assertSame(['00210', '10036'], ProcurationCatalog::codesForDefinition('relpsn'));
        $this->assertSame(['00134'], ProcurationCatalog::codesForDefinition('parcmei'));
        $this->assertSame(['00133'], ProcurationCatalog::codesForDefinition('parcmei-esp'));
        $this->assertSame(['00152', '10012'], ProcurationCatalog::codesForDefinition('pertmei'));
        $this->assertSame(['00209', '10035'], ProcurationCatalog::codesForDefinition('relpmei'));
        $this->assertNull(ProcurationCatalog::codesForDefinition('unknown-definition'));
    }

    public function test_service_names_map_back_to_versioned_codes(): void
    {
        $this->assertSame(['00146'], ProcurationCatalog::codesForServiceName('PGDAS-D - a partir de 01/2018'));
        $this->assertSame(['00146'], ProcurationCatalog::codesForServiceName('  pgdas-d - A PARTIR DE 01/2018 '));
        $this->assertSame(['00006'], ProcurationCatalog::codesForServiceName('Caixa Postal - Mensagens'));
        $this->assertSame(ProcurationCatalog::ALLOWLIST, ProcurationCatalog::codesForServiceName('TODOS'));
        $this->assertSame([], ProcurationCatalog::codesForServiceName('Serviço desconhecido'));
    }

    public function test_operation_to_definition_map_resolves_fiscal_and_parcelment_operations(): void
    {
        $this->assertSame('pgdas-declaracoes', ProcurationCatalog::definitionForOperation('TRANSDECLARACAO11'));
        $this->assertSame('pgdas-declaracoes', ProcurationCatalog::definitionForOperation('GERARDAS12'));
        $this->assertSame('defis', ProcurationCatalog::definitionForOperation('TRANSDECLARACAO141'));
        $this->assertSame('dctfweb', ProcurationCatalog::definitionForOperation('TRANSDECLARACAO310'));
        $this->assertSame('regime-apuracao', ProcurationCatalog::definitionForOperation('EFETUAROPCAOREGIME101'));
        $this->assertSame('situacao-mei', ProcurationCatalog::definitionForOperation('TRANSDECLARACAO151'));
        $this->assertSame('pagamentos', ProcurationCatalog::definitionForOperation('PAGAMENTOS71'));
        $this->assertSame('pagamentos', ProcurationCatalog::definitionForOperation('CONTACONSDOCARRPG73'));
        $this->assertSame('parcsn', ProcurationCatalog::definitionForOperation('GERARDAS161'));
        $this->assertSame('pertsn', ProcurationCatalog::definitionForOperation('PEDIDOSPARC183'));
        $this->assertNull(ProcurationCatalog::definitionForOperation('UNKNOWN999'));
    }

    public function test_operation_routing_uses_catalog_paths(): void
    {
        $this->assertSame('/Consultar', ProcurationCatalog::pathFor('CONSDECLARACAO13'));
        $this->assertSame('/Consultar', ProcurationCatalog::pathFor('PAGAMENTOS71'));
        $this->assertSame('/Apoiar', ProcurationCatalog::pathFor('ENVIOXMLASSINADO81'));
        $this->assertSame('/Monitorar', ProcurationCatalog::pathFor('E0301'));
        $this->assertSame('/Monitorar', ProcurationCatalog::pathFor('SOLICEVENTOS30'));
        $this->assertSame('/Monitorar', ProcurationCatalog::pathFor('OBTEREVENTOS31'));
        $this->assertSame('/Declarar', ProcurationCatalog::pathFor('TRANSDECLARACAO11'));
        $this->assertSame('/Declarar', ProcurationCatalog::pathFor('ENCAPURACAO314'));
        $this->assertSame('/Emitir', ProcurationCatalog::pathFor('GERARDAS161'));
        $this->assertSame('/Emitir', ProcurationCatalog::pathFor('EFETUAROPCAOREGIME101'));
        $this->assertSame('/Emitir', ProcurationCatalog::pathFor('EMITIRCCMEI121'));
        $this->assertSame('/Emitir', ProcurationCatalog::pathFor('RELATORIOSITFIS92'));
    }

    public function test_operation_versions_and_systems_come_from_the_official_catalog(): void
    {
        $this->assertSame('2026-06-01', ProcurationCatalog::TABLE_VERSION);
        $this->assertSame('1', ProcurationCatalog::versionFor('CONSDECLARACAO13'));
        $this->assertSame('1.0', ProcurationCatalog::versionFor('ENVIOXMLASSINADO81'));

        $this->assertSame('PGDASD', ProcurationCatalog::systemFor('CONSDECLARACAO13'));
        $this->assertSame('PGDASD', ProcurationCatalog::systemFor('GERARDAS12'));
        $this->assertSame('REGIMEAPURACAO', ProcurationCatalog::systemFor('CONSULTAROPCAOREGIME103'));
        $this->assertSame('DEFIS', ProcurationCatalog::systemFor('CONSDECLARACAO142'));
        $this->assertSame('CCMEI', ProcurationCatalog::systemFor('DADOSCCMEI122'));
        $this->assertSame('PGMEI', ProcurationCatalog::systemFor('DIVIDAATIVA24'));
        $this->assertSame('DCTFWEB', ProcurationCatalog::systemFor('CONSRECIBO32'));
        $this->assertSame('MIT', ProcurationCatalog::systemFor('CONSAPURACAO316'));
        $this->assertSame('SITFIS', ProcurationCatalog::systemFor('SOLICITARPROTOCOLO91'));
        $this->assertSame('SITFIS', ProcurationCatalog::systemFor('RELATORIOSITFIS92'));
        $this->assertSame('CAIXAPOSTAL', ProcurationCatalog::systemFor('MSGCONTRIBUINTE61'));
        $this->assertSame('DTE', ProcurationCatalog::systemFor('CONSULTASITUACAODTE111'));
        $this->assertSame('PAGTOWEB', ProcurationCatalog::systemFor('PAGAMENTOS71'));
        $this->assertSame('PAGTOWEB', ProcurationCatalog::systemFor('CONTACONSDOCARRPG73'));
        $this->assertSame('PARCSN', ProcurationCatalog::systemFor('PEDIDOSPARC163'));
        $this->assertSame('PERTSN', ProcurationCatalog::systemFor('GERARDAS181'));
        $this->assertSame('PROCURACOES', ProcurationCatalog::systemFor('OBTERPROCURACAO41'));
        $this->assertSame('AUTENTICAPROCURADOR', ProcurationCatalog::systemFor('ENVIOXMLASSINADO81'));
        $this->assertSame('EVENTOSATUALIZACAO', ProcurationCatalog::systemFor('E0301'));
    }

    public function test_system_for_rejects_unknown_operation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported SERPRO operation: UNKNOWN999');

        ProcurationCatalog::systemFor('UNKNOWN999');
    }

    public function test_operations_requiring_procuration_exclude_support_and_mei_consultations(): void
    {
        $this->assertTrue(ProcurationCatalog::requiresForOperation('CONSDECLARACAO13'));
        $this->assertTrue(ProcurationCatalog::requiresForOperation('TRANSDECLARACAO11'));
        $this->assertTrue(ProcurationCatalog::requiresForOperation('GERARDAS161'));
        $this->assertTrue(ProcurationCatalog::requiresForOperation('CONSULTASITUACAODTE111'));
        $this->assertFalse(ProcurationCatalog::requiresForOperation('DADOSCCMEI122'));
        $this->assertFalse(ProcurationCatalog::requiresForOperation('OBTERPROCURACAO41'));
        $this->assertFalse(ProcurationCatalog::requiresForOperation('ENVIOXMLASSINADO81'));
        $this->assertFalse(ProcurationCatalog::requiresForOperation('UNKNOWN999'));
    }

    public function test_resolver_returns_the_index_operation_of_an_available_definition(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ConsultCatalog::operationsFor('pgdas-declaracoes'),
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => true,
        ]);

        $this->assertSame('CONSDECLARACAO13', $resolver->resolve($definition));
    }

    public function test_resolver_accepts_an_explicit_allowed_operation(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ConsultCatalog::operationsFor('pgdas-declaracoes'),
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => true,
        ]);

        $this->assertSame('CONSDECREC15', $resolver->resolve($definition, 'consdecrec15'));
    }

    public function test_resolver_skips_forbidden_fiscal_actions_even_when_persisted(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ['GERARDAS12', 'TRANSDECLARACAO11', 'CONSDECLARACAO13'],
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => true,
        ]);

        $this->assertSame('CONSDECLARACAO13', $resolver->resolve($definition));
    }

    public function test_resolver_rejects_forbidden_polling_code_explicitly(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ConsultCatalog::operationsFor('pgdas-declaracoes'),
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden_fiscal_action');

        $resolver->resolve($definition, 'GERARDAS12');
    }

    public function test_resolver_rejects_monitor_placeholder_codes(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ConsultCatalog::operationsFor('pgdas-declaracoes'),
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consult_operation_unresolved');

        $resolver->resolve($definition, 'MONITOR_pgdas-declaracoes');
    }

    public function test_resolver_allows_explicit_emission_only_when_flagged(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ConsultCatalog::operationsFor('pgdas-declaracoes'),
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => true,
        ]);

        $this->assertSame('GERARDAS12', $resolver->resolve($definition, 'GERARDAS12', true));
    }

    public function test_resolver_fails_closed_for_prospecting_definition(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'parc-paex',
            'operations' => null,
            'availability' => MonitoringDefinition::AVAILABILITY_PROSPECCAO,
            'is_active' => true,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('parcelment_modality_unavailable');

        $resolver->resolve($definition);
    }

    public function test_resolver_fails_closed_for_unavailable_definition_without_fallback(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ConsultCatalog::operationsFor('pgdas-declaracoes'),
            'availability' => 'construction',
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consult_operation_unresolved');

        $resolver->resolve($definition);
    }

    public function test_resolver_fails_closed_for_inactive_definition(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'pgdas-declaracoes',
            'operations' => ConsultCatalog::operationsFor('pgdas-declaracoes'),
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consult_operation_unresolved');

        $resolver->resolve($definition);
    }

    public function test_resolver_fails_closed_for_definition_absent_from_the_catalog(): void
    {
        $resolver = new ConsultOperationResolver;
        $definition = new MonitoringDefinition([
            'id' => 'redesim-vinculos',
            'operations' => null,
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consult_operation_unresolved');

        $resolver->resolve($definition);
    }
}
