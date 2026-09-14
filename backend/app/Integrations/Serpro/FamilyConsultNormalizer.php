<?php

namespace App\Integrations\Serpro;

use App\Models\MonitoringDefinition;

/**
 * Normaliza o resultado de consultas SERPRO por família (porte do `_legacy`
 * adaptado ao OneFisc).
 *
 * Puro (sem banco/HTTP): extrai os campos essenciais de PGDAS-D (índice,
 * emissão explícita GERARDAS12 e filhos da cadeia), Regime de Apuração,
 * DEFIS, MEI, DCTFWeb/MIT, Situação Fiscal e Caixa Postal/DTE. Campo ausente
 * vira `null` — nunca um valor fabricado.
 *
 * Famílias sem normalizador (pagamentos, parcelamentos — normalizados pelo
 * próprio domínio — e códigos desconhecidos) mantêm o resultado bruto em
 * `data` com `normalized => false`: o resultado nunca é descartado nem
 * ganha campos inventados.
 */
final class FamilyConsultNormalizer
{
    /**
     * Famílias com extrator próprio nesta classe. As demais são devolvidas
     * cruas e sinalizadas com `normalized => false`.
     *
     * @var list<string>
     */
    private const NORMALIZED_FAMILIES = ['pgdasd', 'regime', 'defis', 'mei', 'dctfweb', 'sitfis', 'caixa_postal'];

    /**
     * Normaliza um corpo de resposta (ou envelope de fixture com `body`).
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     operation_code: string,
     *     family: string,
     *     normalized: bool,
     *     provenance: string,
     *     catalog_version: string,
     *     data: array<string, mixed>
     * }
     */
    public function normalize(string $operation, array $payload, string $provenance = 'serpro'): array
    {
        $operation = strtoupper(trim($operation));
        $dados = $this->dados($payload);

        // Emissão explícita PGDAS-D: o catálogo não resolve família para
        // ações fiscais, mas o resultado normalizado é o PDF do DAS.
        if ($operation === 'GERARDAS12') {
            return $this->envelope($operation, 'pgdasd', true, $provenance, $this->gerardasEmission($dados));
        }

        $family = ConsultCatalog::familyFor($operation) ?? 'generic';

        if (! in_array($family, self::NORMALIZED_FAMILIES, true)) {
            return $this->envelope($operation, $family, false, $provenance, $dados);
        }

        $data = match ($family) {
            'pgdasd' => $this->pgdasd($operation, $dados),
            'regime' => $this->regime($operation, $dados),
            'defis' => $this->defis($operation, $dados),
            'mei' => $this->mei($operation, $dados),
            'dctfweb' => $this->dctfweb($operation, $dados),
            'sitfis' => $this->sitfis($operation, $dados),
            'caixa_postal' => $this->caixaPostal($operation, $dados),
        };

        return $this->envelope($operation, $family, true, $provenance, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     operation_code: string,
     *     family: string,
     *     normalized: bool,
     *     provenance: string,
     *     catalog_version: string,
     *     data: array<string, mixed>
     * }
     */
    private function envelope(string $operation, string $family, bool $normalized, string $provenance, array $data): array
    {
        return [
            'operation_code' => $operation,
            'family' => $family,
            'normalized' => $normalized,
            'provenance' => $provenance,
            'catalog_version' => MonitoringDefinition::CATALOG_VERSION,
            'data' => $data,
        ];
    }

    /**
     * Extrai o `dados` de negócio de um envelope de fixture (`body`), de um
     * corpo com `dados`/`data` (possivelmente JSON em string e aninhado até
     * 3 níveis) ou do próprio payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dados(array $payload): array
    {
        $body = $this->assoc($payload['body'] ?? null);
        if ($body !== []) {
            $payload = $body;
        }

        $decoded = $this->assoc($payload['dados'] ?? $payload['data'] ?? $payload);

        if ($decoded === []) {
            $fallback = $payload['dados'] ?? $payload['data'] ?? $payload;

            return is_array($fallback) ? $fallback : [];
        }

        for ($depth = 0; $depth < 3; $depth++) {
            $inner = $this->assoc($decoded['dados'] ?? $decoded['data'] ?? null);
            if ($inner === [] || ! $this->looksLikeConsultIndex($inner)) {
                break;
            }

            $decoded = $inner;
        }

        return $decoded;
    }

    /**
     * Normaliza o resultado da emissão explícita GERARDAS12. A resposta é
     * uma lista (`[{pdf, ...}]`) ou um objeto; o período é lido do próprio
     * resultado e nomeia o arquivo de forma factual.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function gerardasEmission(array $dados): array
    {
        $row = $dados;
        if (array_is_list($dados)) {
            $first = $dados[0] ?? null;
            $row = is_array($first) ? $first : [];
        } elseif (isset($dados[0]) && is_array($dados[0])) {
            $row = $dados[0] + $dados;
        }

        $periodo = $this->string($row, 'periodoApuracao', 'periodo_apuracao')
            ?? $this->string($dados, 'periodoApuracao', 'periodo_apuracao');
        $digits = substr(preg_replace('/\D/', '', (string) ($periodo ?? '')) ?? '', 0, 6);

        return [
            'periodo_apuracao' => $periodo,
            'data_consolidacao' => $this->string($row, 'dataConsolidacao', 'data_consolidacao')
                ?? $this->string($dados, 'dataConsolidacao', 'data_consolidacao'),
            'pdf' => $row['pdf'] ?? null,
            'pdf_storage_ref' => $this->string($row, 'pdf_storage_ref')
                ?? $this->string($dados, 'pdf_storage_ref'),
            'pdf_hash_sha256' => $this->string($row, 'pdf_hash_sha256')
                ?? $this->string($dados, 'pdf_hash_sha256'),
            'nome_arquivo' => $digits !== '' ? 'PGDASD-DAS-'.$digits.'.pdf' : 'PGDASD-DAS.pdf',
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function pgdasd(string $operation, array $dados): array
    {
        $periodos = $this->list($dados['periodos'] ?? $dados['Periodos'] ?? null);
        if (is_array($periodos)) {
            return [
                'ano_calendario' => $this->string($dados, 'anoCalendario', 'ano_calendario'),
                'declaracoes' => $this->flattenPgdasdPeriodos($periodos),
            ];
        }

        $rows = $this->list($dados['declaracoes'] ?? null);
        if (is_array($rows)) {
            return [
                'ano_calendario' => $this->string($dados, 'anoCalendario', 'ano_calendario'),
                'declaracoes' => array_map(
                    fn (array $row): array => $this->pgdasdRow($row),
                    array_values(array_filter($rows, 'is_array')),
                ),
            ];
        }

        return $this->pgdasdRow($dados) + ['operation' => $operation];
    }

    /**
     * O índice oficial CONSDECLARACAO13 é `periodos[].operacoes[]` com
     * `indiceDeclaracao`/`indiceDas`; a projeção achata em `declaracoes[]`.
     *
     * @param  list<mixed>  $periodos
     * @return list<array<string, mixed>>
     */
    private function flattenPgdasdPeriodos(array $periodos): array
    {
        $rows = [];

        foreach ($periodos as $periodo) {
            if (! is_array($periodo)) {
                continue;
            }

            $periodoApuracao = $this->string($periodo, 'periodoApuracao', 'periodo_apuracao');
            $operacoes = $this->list($periodo['operacoes'] ?? null);
            if (! is_array($operacoes)) {
                continue;
            }

            foreach (array_values(array_filter($operacoes, 'is_array')) as $operacao) {
                $indiceDeclaracao = is_array($operacao['indiceDeclaracao'] ?? null) ? $operacao['indiceDeclaracao'] : [];
                $indiceDas = is_array($operacao['indiceDas'] ?? null) ? $operacao['indiceDas'] : [];

                $rows[] = $this->pgdasdRow(array_filter([
                    'periodoApuracao' => $periodoApuracao,
                    'tipoOperacao' => $operacao['tipoOperacao'] ?? $operacao['tipo_operacao'] ?? null,
                    ...$indiceDeclaracao,
                    ...$indiceDas,
                ], fn ($value) => $value !== null));
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pgdasdRow(array $row): array
    {
        return [
            'periodo_apuracao' => $this->string($row, 'periodoApuracao', 'periodo_apuracao'),
            'tipo_operacao' => $this->string($row, 'tipoOperacao', 'tipo_operacao'),
            'numero_declaracao' => $this->string($row, 'numeroDeclaracao', 'numero_declaracao'),
            'malha' => $this->string($row, 'malha'),
            'numero_das' => $this->string($row, 'numeroDas', 'numero_das'),
            'das_pago' => $row['dasPago'] ?? $row['das_pago'] ?? null,
            'data_hora_transmissao' => $this->string($row, 'dataHoraTransmissao', 'data_hora_transmissao'),
            'data_hora_emissao_das' => $this->string($row, 'dataHoraEmissaoDas', 'datahoraEmissaoDas', 'data_hora_emissao_das'),
            'nome_arquivo_recibo' => $this->string($row, 'nomeArquivoRecibo', 'nome_arquivo_recibo'),
            'nome_arquivo_declaracao' => $this->string($row, 'nomeArquivoDeclaracao', 'nome_arquivo_declaracao'),
            'nome_arquivo_extrato' => $this->string($row, 'nomeArquivoExtrato', 'nome_arquivo_extrato'),
            'nome_arquivo_maed' => $this->string($row, 'nomeArquivoMaed', 'nome_arquivo_maed'),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function regime(string $operation, array $dados): array
    {
        return [
            'operation' => $operation,
            'ano_calendario' => $this->string($dados, 'anoCalendario'),
            'opcao_regime' => $this->string($dados, 'opcaoRegime', 'opcao'),
            'data_opcao' => $this->string($dados, 'dataOpcao'),
            'resolucao' => $this->string($dados, 'resolucao'),
            'situacao' => $this->string($dados, 'situacao'),
            'anos_calendarios' => $dados['anosCalendarios'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function defis(string $operation, array $dados): array
    {
        $rows = $dados['declaracoes'] ?? null;
        if (is_array($rows)) {
            return [
                'declaracoes' => array_map(fn (array $row): array => [
                    'ano_calendario' => $this->string($row, 'anoCalendario'),
                    'numero_declaracao' => $this->string($row, 'numeroDeclaracao'),
                    'tipo_operacao' => $this->string($row, 'tipoOperacao'),
                    'data_hora_transmissao' => $this->string($row, 'dataHoraTransmissao'),
                ], array_values(array_filter($rows, 'is_array'))),
            ];
        }

        return [
            'operation' => $operation,
            'ano_calendario' => $this->string($dados, 'anoCalendario'),
            'numero_declaracao' => $this->string($dados, 'numeroDeclaracao'),
            'nome_arquivo_recibo' => $this->string($dados, 'nomeArquivoRecibo'),
            'nome_arquivo_declaracao' => $this->string($dados, 'nomeArquivoDeclaracao'),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function mei(string $operation, array $dados): array
    {
        return [
            'operation' => $operation,
            'situacao' => $this->string($dados, 'situacao', 'situacaoCadastral'),
            'valor_inscrito' => $dados['valorInscrito'] ?? null,
            'cnpj' => $this->string($dados, 'cnpj'),
            'nome_empresarial' => $this->string($dados, 'nomeEmpresarial'),
            'data_inicio' => $this->string($dados, 'dataInicio'),
            'vinculos' => $dados['vinculos'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function dctfweb(string $operation, array $dados): array
    {
        return [
            'operation' => $operation,
            'periodo_apuracao' => $this->string($dados, 'periodoApuracao'),
            'numero_recibo' => $this->string($dados, 'numeroRecibo'),
            'situacao' => $this->string($dados, 'situacao', 'situacaoEncerramento'),
            'valor_apurado' => $dados['valorApurado'] ?? null,
            'nome_arquivo_recibo' => $this->string($dados, 'nomeArquivoRecibo'),
            'nome_arquivo_declaracao' => $this->string($dados, 'nomeArquivoDeclaracao'),
            'nome_arquivo_xml' => $this->string($dados, 'nomeArquivoXml'),
            'apuracoes' => $dados['apuracoes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function sitfis(string $operation, array $dados): array
    {
        return [
            'operation' => $operation,
            'protocolo' => $this->string($dados, 'protocolo'),
            'situacao' => $this->string($dados, 'situacao'),
            'nome_arquivo_relatorio' => $this->string($dados, 'nomeArquivoRelatorio'),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function caixaPostal(string $operation, array $dados): array
    {
        $messages = $dados['mensagens'] ?? null;
        if (is_array($messages)) {
            return [
                'operation' => $operation,
                'mensagens' => array_map(fn (array $row): array => [
                    'id_mensagem' => $this->string($row, 'idMensagem'),
                    'assunto' => $this->string($row, 'assunto'),
                    'lida' => $row['lida'] ?? null,
                    'data_hora' => $this->string($row, 'dataHora'),
                    'origem' => $this->string($row, 'origem'),
                ], array_values(array_filter($messages, 'is_array'))),
            ];
        }

        return [
            'operation' => $operation,
            'id_mensagem' => $this->string($dados, 'idMensagem'),
            'assunto' => $this->string($dados, 'assunto'),
            'lida' => $dados['lida'] ?? null,
            'data_hora' => $this->string($dados, 'dataHora'),
            'possui_novas_mensagens' => $dados['possuiNovasMensagens'] ?? null,
            'quantidade' => $dados['quantidade'] ?? null,
            'adesao_caixa_postal_sn' => $dados['adesaoCaixaPostalSn'] ?? null,
            'adesao_ecac' => $dados['adesaoEcac'] ?? null,
            'situacao' => $this->string($dados, 'situacao'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assoc(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>|null
     */
    private function list(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function string(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function looksLikeConsultIndex(array $row): bool
    {
        return array_key_exists('periodos', $row)
            || array_key_exists('declaracoes', $row)
            || array_key_exists('anoCalendario', $row)
            || array_key_exists('ano_calendario', $row);
    }
}
