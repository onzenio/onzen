<?php

namespace App\Integrations\Serpro;

/**
 * Tabela oficial Serviços x Procurações do Integra Contador como dado
 * versionado (não em código espalhado).
 *
 * Fonte: "Serviços x Procurações - Integra Contador" (Apicenter SERPRO),
 * edição de 1 de junho de 2026. Colunas aproveitadas: `idServico`,
 * `Cód. Procuração` e `Nome do Serviço (procuração eCAC)`.
 *
 * - `ALLOWLIST`: todos os códigos aceitos no cadastro de procuração.
 * - `SERVICE_NAMES`: código → nomes eCAC (a resposta do `OBTERPROCURACAO41`
 *   traz nomes, não códigos; nomes fora do mapa são ignorados com log).
 * - `DEFINITION_CODES`: definição de monitoramento → códigos exigidos.
 * - `OPERATION_WIRE`: operação → caminho (`/Consultar`, `/Apoiar`, …) e
 *   `versaoSistema` do catálogo.
 */
final class ProcurationCatalog
{
    public const TABLE_VERSION = '2026-06-01';

    /** Horas de validade do cache de verificação (`OBTERPROCURACAO41`). */
    public const VERIFY_CACHE_TTL_HOURS = 24;

    /** Antecedência do alerta de vencimento (procuração e A1). */
    public const EXPIRY_WARNING_DAYS = 15;

    /** @var list<string> */
    public const ALLOWLIST = [
        '00146', '00103', '00060', '00050', '00002', '00004', '00006', '00051', '00229',
        '00076', '00188', '00125', '00149', '10011', '00210', '10036',
        '00134', '00133', '00152', '10012', '00209', '10035',
    ];

    /**
     * Código de procuração → nomes do serviço no cadastro eCAC.
     *
     * @var array<string, list<string>>
     */
    public const SERVICE_NAMES = [
        '00146' => ['PGDAS-D - a partir de 01/2018'],
        '00103' => ['Acessar o sistema DCTFWeb'],
        '00060' => ['Simples Nacional - Opção pelo Regime de Apuração de Receitas'],
        '00050' => ['Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico'],
        '00002' => ['Situação Fiscal do Contribuinte'],
        '00004' => ['Pagamentos - Comprovante de Arrecadação'],
        '00006' => ['Caixa Postal - Mensagens'],
        '00051' => ['Processos Digitais (e-Processo)'],
        '00229' => ['Consulta Declaração do Microempreendedor Individual'],
        '00076' => ['Parcelamento de Débitos do Simples Nacional'],
        '00188' => ['Solicitar, acompanhar e emitir DAS de parcelamento'],
        '00125' => ['Parcelamento Especial Simples Nacional'],
        '00149' => ['Programa Especial Regularização Tributária - PERT-SN'],
        '10011' => ['Programa Especial Regularização Tributária - PERT-SN'],
        '00210' => ['Parcelar dívidas do SN pela LC 193/2022 (RELP)'],
        '10036' => ['Parcelar dívidas do SN pela LC 193/2022 (RELP)'],
        '00134' => ['Parcelamento - Microempreendedor Individual'],
        '00133' => ['Parcelamento Especial - Microempreendedor Individual'],
        '00152' => ['Programa Especial de Regularização Tributária - PERT-MEI'],
        '10012' => ['Programa Especial de Regularização Tributária - PERT-MEI'],
        '00209' => ['Parcelar dívidas do MEI pela LC 193/2022 (RELP)'],
        '10035' => ['Parcelar dívidas do MEI pela LC 193/2022 (RELP)'],
    ];

    /**
     * Definição de monitoramento → códigos de procuração exigidos.
     * Definições fora deste mapa não exigem código específico (qualquer
     * procuração válida satisfaz a elegibilidade).
     *
     * @var array<string, list<string>>
     */
    public const DEFINITION_CODES = [
        'pgdas-declaracoes' => ['00146'],
        'defis' => ['00146'],
        'regime-apuracao' => ['00060'],
        'dctfweb' => ['00103'],
        'mit' => ['00103'],
        'situacao-fiscal' => ['00002'],
        'caixa-postal' => ['00006'],
        'dte' => ['00050'],
        'pagamentos' => ['00004'],
        'processo-digital' => ['00051'],
        'situacao-mei' => [],
        'parcsn' => ['00076', '00188'],
        'parcsn-esp' => ['00125'],
        'pertsn' => ['00149', '10011'],
        'relpsn' => ['00210', '10036'],
        'parcmei' => ['00134'],
        'parcmei-esp' => ['00133'],
        'pertmei' => ['00152', '10012'],
        'relpmei' => ['00209', '10035'],
    ];

    /**
     * Definições que passam a exigir procuração pela tabela oficial
     * (antes semeadas com `requires_procuracao=false`).
     *
     * @var list<string>
     */
    public const NEWLY_REQUIRED_DEFINITIONS = ['pgdas-declaracoes', 'defis', 'regime-apuracao'];

    /**
     * Operação → códigos exigidos, para os casos em que a exigência é da
     * operação e não da definição (pares de parcelamento valem como
     * alternativa: basta UM dos códigos do par).
     *
     * @var array<string, list<string>>
     */
    public const OPERATION_CODES = [
        'TRANSDECLARACAO11' => ['00146'],
        'GERARDAS12' => ['00146'],
        'TRANSDECLARACAO141' => ['00146'],
        'TRANSDECLARACAO310' => ['00103'],
        'GERARGUIA31' => ['00103'],
        'EFETUAROPCAOREGIME101' => ['00060'],
        'TRANSDECLARACAO151' => ['00229'],
        'GERARDASEXCESSO153' => ['00229'],
    ];

    /** @var array<string, string> operação Emitir/Declarar → definição dona */
    public const OPERATION_DEFINITIONS = [
        'TRANSDECLARACAO11' => 'pgdas-declaracoes',
        'GERARDAS12' => 'pgdas-declaracoes',
        'TRANSDECLARACAO141' => 'defis',
        'TRANSDECLARACAO310' => 'dctfweb',
        'GERARGUIA31' => 'dctfweb',
        'EFETUAROPCAOREGIME101' => 'regime-apuracao',
        'TRANSDECLARACAO151' => 'situacao-mei',
        'GERARDASEXCESSO153' => 'situacao-mei',
        'PAGAMENTOS71' => 'pagamentos',
        'COMPARRECADACAO72' => 'pagamentos',
        'CONTACONSDOCARRPG73' => 'pagamentos',
        'CONSPROCPORINTER271' => 'processo-digital',
        'OBTLISTDOCSPROC272' => 'processo-digital',
        'OBTDOCPROC273' => 'processo-digital',
        'CONSCOMUNINTIM274' => 'processo-digital',
    ];

    /**
     * Operações que trafegam sem o header `autenticar_procurador_token`.
     *
     * @var list<string>
     */
    public const TOKENLESS_OPERATIONS = ['OBTERPROCURACAO41', 'ENVIOXMLASSINADO81'];

    /** @return list<string> */
    public static function allowlist(): array
    {
        return self::ALLOWLIST;
    }

    public static function isAllowedCode(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::ALLOWLIST, true);
    }

    /** @return list<string>|null */
    public static function codesForDefinition(string $definitionId): ?array
    {
        return self::DEFINITION_CODES[$definitionId] ?? null;
    }

    /**
     * Nome do serviço eCAC → códigos. Um nome pode mapear para mais de um
     * código (pares de parcelamento); a comparação ignora caixa e espaços.
     *
     * @return list<string>
     */
    public static function codesForServiceName(string $name): array
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === 'todos') {
            return self::ALLOWLIST;
        }
        $codes = [];
        foreach (self::SERVICE_NAMES as $code => $names) {
            foreach ($names as $known) {
                if (mb_strtolower(trim($known)) === $needle) {
                    $codes[] = (string) $code;
                }
            }
        }

        return $codes;
    }

    /**
     * Família consultiva do catálogo → definição de monitoramento dona.
     */
    public static function definitionForFamily(?string $family): ?string
    {
        return match ($family) {
            'pgdasd' => 'pgdas-declaracoes',
            'regime' => 'regime-apuracao',
            'defis' => 'defis',
            'mei' => 'situacao-mei',
            'dctfweb' => 'dctfweb',
            'sitfis' => 'situacao-fiscal',
            'caixa_postal' => 'caixa-postal',
            'pagamentos' => 'pagamentos',
            'parcelment' => null,
            default => null,
        };
    }

    public static function definitionForOperation(string $operationCode): ?string
    {
        $code = strtoupper(trim($operationCode));
        if (isset(self::OPERATION_DEFINITIONS[$code])) {
            return self::OPERATION_DEFINITIONS[$code];
        }
        $modality = ConsultCatalog::parcelmentModality($code);
        if ($modality !== null) {
            return strtolower($modality);
        }

        return null;
    }

    /**
     * A operação exige procuração válida quando o autor não for o
     * contribuinte. Operações de apoio (`OBTERPROCURACAO41`,
     * `ENVIOXMLASSINADO81`) e de famílias sem exigência (consultas MEI,
     * Redesim, SICALC) retornam false.
     */
    public static function requiresForOperation(string $operationCode): bool
    {
        $code = strtoupper(trim($operationCode));
        if (in_array($code, self::TOKENLESS_OPERATIONS, true)) {
            return false;
        }
        if (isset(self::OPERATION_CODES[$code]) || isset(self::OPERATION_DEFINITIONS[$code])) {
            return true;
        }
        if (ConsultCatalog::parcelmentModality($code) !== null) {
            return true;
        }
        if (ConsultCatalog::isParcelmentConsult($code)) {
            return true;
        }
        $family = ConsultCatalog::familyFor($code);
        if ($family === null) {
            return false;
        }
        $definition = self::definitionForFamily($family);
        if ($definition === 'situacao-mei') {
            return false;
        }
        // Caixa postal/DTE compartilham a família; o DTE exige (00050).
        if ($family === 'caixa_postal') {
            return true;
        }

        return $definition !== null;
    }

    /**
     * Caminho do catálogo para a operação (`/Consultar`, `/Apoiar`,
     * `/Declarar`, `/Emitir`, `/Monitorar`). Nada de `POST /<operationCode>`.
     */
    public static function pathFor(string $operationCode): string
    {
        $code = strtoupper(trim($operationCode));
        if ($code === 'ENVIOXMLASSINADO81') {
            return '/Apoiar';
        }
        if (in_array($code, ['E0301', 'E0601', 'E0701'], true) || str_starts_with($code, 'SOLICEVENTOS') || str_starts_with($code, 'OBTEREVENTOS')) {
            return '/Monitorar';
        }
        if (str_starts_with($code, 'TRANSDECLARACAO') || str_starts_with($code, 'ENCAPURACAO')) {
            return '/Declarar';
        }
        if (str_starts_with($code, 'GERAR') || str_starts_with($code, 'EFETUAR') || str_starts_with($code, 'EMITIR') || str_starts_with($code, 'CONSOLIDAR') || $code === 'RELATORIOSITFIS92' || str_starts_with($code, 'COMPARRECADACAO')) {
            return '/Emitir';
        }

        return '/Consultar';
    }

    /**
     * `versaoSistema` do catálogo para a operação. Valores confirmados nos
     * exemplos oficiais; demais operações usam o padrão "1".
     */
    public static function versionFor(string $operationCode): string
    {
        return match (strtoupper(trim($operationCode))) {
            'ENVIOXMLASSINADO81' => '1.0',
            default => '1',
        };
    }

    /**
     * `idSistema` do catálogo para a operação.
     *
     * @throws \InvalidArgumentException
     */
    public static function systemFor(string $operationCode): string
    {
        $code = strtoupper(trim($operationCode));
        if ($code === 'OBTERPROCURACAO41') {
            return 'PROCURACOES';
        }
        if ($code === 'ENVIOXMLASSINADO81') {
            return 'AUTENTICAPROCURADOR';
        }
        if (in_array($code, ['E0301', 'E0601', 'E0701'], true) || str_starts_with($code, 'SOLICEVENTOS') || str_starts_with($code, 'OBTEREVENTOS')) {
            return 'EVENTOSATUALIZACAO';
        }
        // Explicit PGDAS-D DAS emission (monitoring-pgdasd-pdfs): GERARDAS12
        // is not a parcelment operation, so it must resolve to PGDASD before
        // the parcelment branch below (which would throw for it).
        if ($code === 'GERARDAS12') {
            return 'PGDASD';
        }
        if (ConsultCatalog::isParcelmentConsult($code) || str_starts_with($code, 'GERARDAS')) {
            $modality = ConsultCatalog::parcelmentModality($code);

            return $modality !== null ? str_replace('_', '-', $modality) : throw new \InvalidArgumentException("Unsupported SERPRO operation: {$operationCode}");
        }
        $family = ConsultCatalog::familyFor($code);
        if ($family === null) {
            throw new \InvalidArgumentException("Unsupported SERPRO operation: {$operationCode}");
        }

        return match ($family) {
            'pgdasd' => 'PGDASD',
            'regime' => 'REGIMEAPURACAO',
            'defis' => 'DEFIS',
            'mei' => str_starts_with($code, 'DIVIDAATIVA') ? 'PGMEI' : 'CCMEI',
            'dctfweb' => in_array($code, ['SITUACAOENC315', 'CONSAPURACAO316', 'LISTAAPURACOES317'], true) ? 'MIT' : 'DCTFWEB',
            'sitfis' => 'SITFIS',
            'caixa_postal' => $code === 'CONSULTASITUACAODTE111' ? 'DTE' : 'CAIXAPOSTAL',
            'pagamentos' => 'PAGTOWEB',
            default => throw new \InvalidArgumentException("Unsupported SERPRO operation: {$operationCode}"),
        };
    }
}
