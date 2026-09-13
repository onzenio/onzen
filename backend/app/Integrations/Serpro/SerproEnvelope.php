<?php

namespace App\Integrations\Serpro;

use InvalidArgumentException;
use JsonException;

/**
 * Envelope oficial de 3 partes (contratante/autor/contribuinte) + pedido.
 *
 * Formato confirmado nos exemplos oficiais do Integra Contador:
 * `POST {base}/integra-contador/v1/{Consultar,Apoiar,…}` com `contratante`,
 * `autorPedidoDados`, `contribuinte` (`numero` + `tipo`: 1 = PF, 2 = PJ) e
 * `pedidoDados` (`idSistema`, `idServico`, `versaoSistema`, `dados`).
 *
 * `dados` é serializado como string JSON, como o gateway exige (evidência:
 * binding 400 com objeto no `/Consultar` e no `/Apoiar`). Cada operação com
 * formato próprio é montada por {@see dadosFor()}; as demais recebem
 * `parameters` sem alteração. Sem HTTP, sem banco, sem estado.
 */
final class SerproEnvelope
{
    /**
     * @param  array{numero?: string, tipo?: int}  $contratante
     * @param  array{numero?: string, tipo?: int}  $autor
     * @param  array{numero?: string, tipo?: int}  $contribuinte
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException quando a operação não pertence ao catálogo.
     */
    public static function make(array $contratante, array $autor, array $contribuinte, string $operationCode, array $parameters = []): array
    {
        $operation = strtoupper(trim($operationCode));
        $autor = self::party($autor);
        $contribuinte = self::party($contribuinte);

        // O termo do Autentica-Procurador é assinado pelo próprio autor; sem
        // contribuinte explícito ele ocupa as duas pontas.
        if ($operation === 'ENVIOXMLASSINADO81' && $contribuinte['numero'] === '' && $autor['numero'] !== '') {
            $contribuinte = $autor;
        }

        return [
            'contratante' => self::party($contratante),
            'autorPedidoDados' => $autor,
            'contribuinte' => $contribuinte,
            'pedidoDados' => [
                'idSistema' => ProcurationCatalog::systemFor($operation),
                'idServico' => $operation,
                'versaoSistema' => ProcurationCatalog::versionFor($operation),
                'dados' => self::encodeDados(self::dadosFor($operation, $parameters)),
            ],
        ];
    }

    /**
     * @return array{numero: string, tipo: int}
     */
    public static function partyFor(string $document, string|int|null $personType = null): array
    {
        $digits = preg_replace('/\D/', '', $document) ?? '';

        return [
            'numero' => $digits,
            'tipo' => self::typeFor($digits, $personType),
        ];
    }

    /**
     * 1 = PF (documento com até 11 dígitos), 2 = PJ. `person_type` explícito
     * prevalece sobre a inferência.
     */
    public static function typeFor(string $document, string|int|null $personType = null): int
    {
        if ($personType !== null && $personType !== '') {
            return self::explicitType($personType);
        }

        $digits = preg_replace('/\D/', '', $document) ?? '';

        return strlen($digits) <= 11 ? 1 : 2;
    }

    /**
     * Formato oficial de `pedidoDados.dados` por operação, montado a partir
     * de `parameters`; operações sem formato próprio recebem os parâmetros
     * sem alteração.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function dadosFor(string $operationCode, array $parameters = []): array
    {
        // A polling payload is already the protocol envelope and must pass
        // through untouched even for operations with a shaped `dados` (the
        // explicit DAS emission polls GERARDAS12 with {protocol, poll}).
        if (array_key_exists('protocol', $parameters) || ($parameters['poll'] ?? null) === true) {
            return $parameters;
        }

        $keys = match (strtoupper(trim($operationCode))) {
            'OBTERPROCURACAO41' => ['outorgante', 'tipoOutorgante', 'outorgado', 'tipoOutorgado'],
            'ENVIOXMLASSINADO81' => ['xml'],
            'GERARDAS12' => ['periodo_apuracao', 'data_consolidacao'],
            'CONSDECREC15' => ['numeroDeclaracao', 'periodoApuracao'],
            'CONSULTIMADECREC14' => ['anoCalendario'],
            'CONSEXTRATO16' => ['numeroDas'],
            default => null,
        };

        if ($keys === null) {
            return $parameters;
        }

        $dados = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $parameters)) {
                $dados[$key] = $parameters[$key];
            }
        }

        return $dados;
    }

    private static function explicitType(string|int $personType): int
    {
        $value = trim((string) $personType);

        if ($value === '1') {
            return 1;
        }
        if ($value === '2') {
            return 2;
        }

        return match (strtoupper($value)) {
            'PF' => 1,
            'PJ' => 2,
            default => throw new InvalidArgumentException('serpro_envelope_person_type_invalid'),
        };
    }

    /**
     * @param  array{numero?: string, tipo?: int}  $party
     * @return array{numero: string, tipo: int}
     */
    private static function party(array $party): array
    {
        $numero = preg_replace('/\D/', '', (string) ($party['numero'] ?? '')) ?? '';

        return [
            'numero' => $numero,
            'tipo' => isset($party['tipo']) ? (int) $party['tipo'] : self::typeFor($numero),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     *
     * @throws JsonException
     */
    private static function encodeDados(array $dados): string
    {
        return json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
