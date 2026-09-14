<?php

namespace App\Integrations\Serpro;

use InvalidArgumentException;

class SerproEnvelope
{
    /**
     * Monta o envelope de 3 partes do Integra Contador:
     * contratante + autor do pedido + pedido (operação e contribuinte).
     *
     * @param array<string, mixed> $params
     * @return array{contratante: array{tipo: string, numero: string}, autorPedidoDados: array{tipo: string, numero: string}, pedido: array<string, mixed>}
     */
    public static function build(
        string $operation,
        string $contractorDoc,
        string $authorDoc,
        string $taxpayerDoc,
        array $params = [],
    ): array {
        return [
            'contratante' => [
                'tipo' => self::personType($contractorDoc),
                'numero' => self::digits($contractorDoc),
            ],
            'autorPedidoDados' => [
                'tipo' => self::personType($authorDoc),
                'numero' => self::digits($authorDoc),
            ],
            'pedido' => [
                'operacao' => $operation,
                'contribuinte' => [
                    'tipo' => self::personType($taxpayerDoc),
                    'numero' => self::digits($taxpayerDoc),
                ],
                ...$params,
            ],
        ];
    }

    public static function personType(string $doc): string
    {
        return match (strlen(self::digits($doc))) {
            11 => 'PF',
            14 => 'PJ',
            default => throw new InvalidArgumentException('Documento inválido: esperado CPF (11) ou CNPJ (14) dígitos.'),
        };
    }

    public static function digits(string $doc): string
    {
        return (string) preg_replace('/\D/', '', $doc);
    }
}
