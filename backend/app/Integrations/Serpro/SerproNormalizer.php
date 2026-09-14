<?php

namespace App\Integrations\Serpro;

class SerproNormalizer
{
    /**
     * Normaliza o payload por família, extraindo campos essenciais.
     * Ausentes ficam nulos/vazios — nunca inventados. Família sem
     * normalizador retorna bruto controlado + sinalização.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalize(string $family, array $payload): array
    {
        return match ($family) {
            'pgdasd' => [
                'normalized' => true, 'family' => $family,
                'exercicio' => $payload['exercicio'] ?? null,
                'periodo' => $payload['periodo'] ?? null,
                'recibo' => $payload['recibo'] ?? null,
                'valor_apurado' => $payload['valor_apurado'] ?? null,
                'valor_pago' => $payload['valor_pago'] ?? null,
                'situacao' => $payload['situacao'] ?? null,
            ],
            'regime' => [
                'normalized' => true, 'family' => $family,
                'regime_apuracao' => $payload['regime_apuracao'] ?? null,
                'vigencia' => $payload['vigencia'] ?? null,
            ],
            'defis' => [
                'normalized' => true, 'family' => $family,
                'exercicio' => $payload['exercicio'] ?? null,
                'situacao' => $payload['situacao'] ?? null,
                'recibo' => $payload['recibo'] ?? null,
            ],
            'mei' => [
                'normalized' => true, 'family' => $family,
                'situacao' => $payload['situacao'] ?? null,
                'exercicio' => $payload['exercicio'] ?? null,
            ],
            'dctfweb' => [
                'normalized' => true, 'family' => $family,
                'competencia' => $payload['competencia'] ?? null,
                'situacao' => $payload['situacao'] ?? null,
                'recibo' => $payload['recibo'] ?? null,
            ],
            'mit' => [
                'normalized' => true, 'family' => $family,
                'competencia' => $payload['competencia'] ?? null,
                'situacao' => $payload['situacao'] ?? null,
            ],
            'sitfis' => [
                'normalized' => true, 'family' => $family,
                'situacao_fiscal' => $payload['situacao_fiscal'] ?? null,
                'cnd_disponivel' => $payload['cnd_disponivel'] ?? null,
            ],
            'caixa-postal', 'dte' => [
                'normalized' => true, 'family' => $family,
                'mensagens_total' => is_array($payload['mensagens'] ?? null) ? count($payload['mensagens']) : 0,
                'nao_lidas' => is_array($payload['mensagens'] ?? null)
                    ? count(array_filter($payload['mensagens'], fn ($m) => ! ($m['lida'] ?? true)))
                    : 0,
            ],
            'pagamentos' => [
                'normalized' => true, 'family' => $family,
                'pagamentos_total' => is_array($payload['pagamentos'] ?? null) ? count($payload['pagamentos']) : 0,
            ],
            default => [
                'normalized' => false,
                'family' => $family,
                'summary' => 'Família sem normalizador: resultado bruto controlado.',
            ],
        };
    }

    public static function familyForOperation(string $operation): string
    {
        return match (true) {
            str_starts_with($operation, 'consultar-pgdasd') => 'pgdasd',
            str_starts_with($operation, 'consultar-regime') => 'regime',
            str_starts_with($operation, 'consultar-defis') => 'defis',
            str_starts_with($operation, 'consultar-mei') => 'mei',
            str_starts_with($operation, 'consultar-dctfweb') => 'dctfweb',
            str_starts_with($operation, 'consultar-mit') => 'mit',
            str_starts_with($operation, 'consultar-sitfis') => 'sitfis',
            str_starts_with($operation, 'consultar-caixa-postal') => 'caixa-postal',
            str_starts_with($operation, 'consultar-dte') => 'dte',
            str_starts_with($operation, 'consultar-pagamentos') => 'pagamentos',
            str_starts_with($operation, 'consultar-parcelamentos') => 'parcelamentos',
            default => 'desconhecida',
        };
    }
}
