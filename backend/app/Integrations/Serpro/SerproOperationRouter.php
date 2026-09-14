<?php

namespace App\Integrations\Serpro;

use InvalidArgumentException;

class SerproOperationRouter
{
    public static function pathFor(string $operation): string
    {
        if (str_starts_with($operation, 'consultar-')) {
            return "consultar/{$operation}";
        }

        if (str_starts_with($operation, 'emitir-') || str_starts_with($operation, 'gerar-')) {
            return "acoes/{$operation}";
        }

        throw new InvalidArgumentException("Operação sem rota conhecida: {$operation}.");
    }

    public static function isWrite(string $operation): bool
    {
        return str_starts_with($operation, 'emitir-')
            || str_starts_with($operation, 'gerar-')
            || str_starts_with($operation, 'transmitir-')
            || str_starts_with($operation, 'declarar-');
    }

    public static function urlFor(string $operation, ?string $environment = null): string
    {
        $environment ??= (string) config('monitoring.environment', 'homologacao');

        return rtrim((string) config('monitoring.base_url'), '/').'/'.self::pathFor($operation);
    }
}
