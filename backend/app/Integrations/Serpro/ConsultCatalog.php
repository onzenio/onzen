<?php

namespace App\Integrations\Serpro;

/**
 * Catálogo Integra Contador: definições de monitoramento → operações de
 * consulta oficiais, famílias, módulos, fixture keys e operações que não
 * podem rodar no ciclo automático.
 *
 * Dados e lookups apenas: sem HTTP, sem banco, sem estado.
 */
final class ConsultCatalog
{
    /** @var list<string> */
    public const EXPLICIT_EMISSION_OPERATIONS = ['GERARDAS12'];

    /** @var list<string> */
    public const FORBIDDEN_POLLING = [
        'TRANSDECLARACAO11',
        'TRANSDECLARACAO141',
        'TRANSDECLARACAO151',
        'TRANSDECLARACAO310',
        'GERARDAS12',
        'GERARDASCOBRANCA17',
        'GERARDASPROCESSO18',
        'GERARDASAVULSO19',
        'GERARDASPDF21',
        'GERARDASCODBARRA22',
        'EFETUAROPCAOREGIME101',
        'EMITIRCCMEI121',
        'GERARGUIA31',
        'GERARGUIAMAED36',
        'GERARGUIACOMABATIMENTO311',
        'GERARGUIAANDAMENTO313',
        'ENCAPURACAO314',
        'E0301',
    ];

    /** @var array<string, list<string>> */
    public const CONSULT_OPERATIONS = [
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

    /** @var array<string, list<string>> */
    public const PARCELMENT_OPERATIONS = [
        'parcsn' => ['PEDIDOSPARC163', 'OBTERPARC164', 'PARCELASPARAGERAR162', 'DETPAGTOPARC165', 'GERARDAS161'],
        'parcsn-esp' => ['PEDIDOSPARC173', 'OBTERPARC174', 'PARCELASPARAGERAR172', 'DETPAGTOPARC175', 'GERARDAS171'],
        'pertsn' => ['PEDIDOSPARC183', 'OBTERPARC184', 'PARCELASPARAGERAR182', 'DETPAGTOPARC185', 'GERARDAS181'],
        'relpsn' => ['PEDIDOSPARC193', 'OBTERPARC194', 'PARCELASPARAGERAR192', 'DETPAGTOPARC195', 'GERARDAS191'],
        'parcmei' => ['PEDIDOSPARC203', 'OBTERPARC204', 'PARCELASPARAGERAR202', 'DETPAGTOPARC205', 'GERARDAS201'],
        'parcmei-esp' => ['PEDIDOSPARC213', 'OBTERPARC214', 'PARCELASPARAGERAR212', 'DETPAGTOPARC215', 'GERARDAS211'],
        'pertmei' => ['PEDIDOSPARC223', 'OBTERPARC224', 'PARCELASPARAGERAR222', 'DETPAGTOPARC225', 'GERARDAS221'],
        'relpmei' => ['PEDIDOSPARC233', 'OBTERPARC234', 'PARCELASPARAGERAR232', 'DETPAGTOPARC235', 'GERARDAS231'],
    ];

    /** @var array<string, list<string>> */
    public const PERSON_TYPES = [
        'pgdas-declaracoes' => ['PJ'],
        'regime-apuracao' => ['PJ'],
        'defis' => ['PJ'],
        'situacao-mei' => ['PF', 'PJ'],
    ];

    /** @var list<string> */
    public const PROSPECCAO_DEFINITIONS = ['parc-paex', 'parc-sipade'];

    /**
     * Canonical parcelment modality labels (uppercase). Mirrors the keys of
     * PARCELMENT_OPERATIONS; kept as an explicit list because PHP constants
     * cannot derive values from other constants dynamically.
     *
     * @var list<string>
     */
    public const PARCELMENT_MODALITIES = ['PARCSN', 'PARCSN-ESP', 'PERTSN', 'RELPSN', 'PARCMEI', 'PARCMEI-ESP', 'PERTMEI', 'RELPMEI'];

    /**
     * @return list<string>|null
     */
    public static function operationsFor(string $definitionId): ?array
    {
        return self::CONSULT_OPERATIONS[$definitionId] ?? self::PARCELMENT_OPERATIONS[$definitionId] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function definitionIdsForModule(string $module): array
    {
        return match ($module) {
            'simples' => ['pgdas-declaracoes', 'regime-apuracao', 'defis', 'situacao-mei'],
            'dctfweb', 'declaracoes-dctfweb' => ['dctfweb', 'mit'],
            'declaracoes' => ['pgdas-declaracoes', 'defis'],
            'declaracoes-pgdas' => ['pgdas-declaracoes'],
            'declaracoes-defis' => ['defis'],
            'situacao', 'situacao-fiscal', 'situacao-relatorio' => ['situacao-fiscal'],
            'situacao-comprovantes' => ['pagamentos'],
            'caixa-postal', 'caixas-postais', 'caixa-postal-receita' => ['caixa-postal', 'dte'],
            'parcelamentos-simples-nacional' => ['parcsn', 'pertsn', 'relpsn'],
            'parcelamentos-especiais' => ['parcsn-esp', 'parcmei-esp'],
            'parcelamentos-pgfn' => ['parc-paex', 'parc-sipade'],
            default => [],
        };
    }

    public static function indexOperation(string $definitionId): ?string
    {
        $operations = self::operationsFor($definitionId);
        if ($operations === null) {
            return null;
        }

        foreach ($operations as $code) {
            if (! self::isExplicitFiscalAction($code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    public static function personTypesFor(string $definitionId): ?array
    {
        return self::PERSON_TYPES[$definitionId] ?? null;
    }

    public static function isConsultDefinition(string $definitionId): bool
    {
        return isset(self::CONSULT_OPERATIONS[$definitionId]) || isset(self::PARCELMENT_OPERATIONS[$definitionId]);
    }

    public static function isProspeccao(string $definitionId): bool
    {
        return in_array($definitionId, self::PROSPECCAO_DEFINITIONS, true);
    }

    public static function isExplicitEmission(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::EXPLICIT_EMISSION_OPERATIONS, true);
    }

    public static function isForbiddenPolling(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === 'GERARDAS') {
            return true;
        }
        if ((bool) preg_match('/^GERARDAS(?:161|171|181|191|201|211|221|231)$/', $code)) {
            return true;
        }
        if (in_array($code, self::FORBIDDEN_POLLING, true)) {
            return true;
        }

        return (bool) preg_match('/^(?:TRANSDECLARACAO|GERARDAS12|GERARDASCOBRANCA|GERARDASPROCESSO|GERARDASAVULSO)/', $code);
    }

    public static function isExplicitFiscalAction(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === 'GERARDAS') {
            return true;
        }

        return self::isForbiddenPolling($code) || (bool) preg_match('/^GERARDAS(?:161|171|181|191|201|211|221|231)$/', $code);
    }

    public static function isParcelmentConsult(string $code): bool
    {
        return (bool) preg_match('/^(?:PEDIDOSPARC|OBTERPARC|PARCELASPARAGERAR|DETPAGTOPARC)\d+$/', strtoupper($code));
    }

    public static function parcelmentModality(string $code): ?string
    {
        $code = strtoupper($code);
        foreach (self::PARCELMENT_OPERATIONS as $definitionId => $operations) {
            if (in_array($code, $operations, true)) {
                return strtoupper($definitionId);
            }
        }

        if (preg_match('/^(?:PEDIDOSPARC|OBTERPARC|PARCELASPARAGERAR|DETPAGTOPARC|GERARDAS)(\d+)$/', $code, $matches) !== 1) {
            return null;
        }

        return match ($matches[1]) {
            '161', '162', '163', '164', '165' => 'PARCSN',
            '171', '172', '173', '174', '175' => 'PARCSN-ESP',
            '181', '182', '183', '184', '185' => 'PERTSN',
            '191', '192', '193', '194', '195' => 'RELPSN',
            '201', '202', '203', '204', '205' => 'PARCMEI',
            '211', '212', '213', '214', '215' => 'PARCMEI-ESP',
            '221', '222', '223', '224', '225' => 'PERTMEI',
            '231', '232', '233', '234', '235' => 'RELPMEI',
            default => null,
        };
    }

    public static function gerardasForModality(string $modality): ?string
    {
        $key = strtolower(str_replace('_', '-', trim($modality)));
        $operations = self::PARCELMENT_OPERATIONS[$key] ?? null;
        if ($operations === null) {
            return null;
        }
        foreach ($operations as $code) {
            if (str_starts_with(strtoupper($code), 'GERARDAS')) {
                return strtoupper($code);
            }
        }

        return null;
    }

    public static function familyFor(string $code): ?string
    {
        $code = strtoupper($code);
        if (str_starts_with($code, 'CONSDECLARACAO13') || in_array($code, ['CONSULTIMADECREC14', 'CONSDECREC15', 'CONSEXTRATO16'], true)) {
            return 'pgdasd';
        }
        if (in_array($code, ['CONSULTARANOSCALENDARIOS102', 'CONSULTAROPCAOREGIME103', 'CONSULTARRESOLUCAO104'], true)) {
            return 'regime';
        }
        if (in_array($code, ['CONSDECLARACAO142', 'CONSULTIMADECREC143', 'CONSDECREC144'], true)) {
            return 'defis';
        }
        if (in_array($code, ['DIVIDAATIVA24', 'DADOSCCMEI122', 'CCMEISITCADASTRAL123'], true)) {
            return 'mei';
        }
        if (in_array($code, ['CONSRECIBO32', 'CONSDECCOMPLETA33', 'CONSXMLDECLARACAO38', 'SITUACAOENC315', 'CONSAPURACAO316', 'LISTAAPURACOES317'], true)) {
            return 'dctfweb';
        }
        if (in_array($code, ['SOLICITARPROTOCOLO91', 'RELATORIOSITFIS92'], true)) {
            return 'sitfis';
        }
        if (in_array($code, ['MSGCONTRIBUINTE61', 'MSGDETALHAMENTO62', 'INNOVAMSG63', 'CONSULTASITUACAODTE111'], true)) {
            return 'caixa_postal';
        }
        if (in_array($code, ['PAGAMENTOS71', 'CONTACONSDOCARRPG73'], true)) {
            return 'pagamentos';
        }
        if (self::isParcelmentConsult($code)) {
            return 'parcelment';
        }

        return null;
    }

    public static function fixtureKey(string $code): string
    {
        $code = strtoupper(trim($code));
        foreach (['PEDIDOSPARC', 'OBTERPARC', 'PARCELASPARAGERAR', 'DETPAGTOPARC'] as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return $prefix;
            }
        }

        return $code;
    }
}
