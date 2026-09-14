<?php

namespace App\Integrations\Serpro;

use RuntimeException;

class SerproFixtureProvider
{
    public function path(string $operation): string
    {
        return rtrim((string) config('monitoring.fixtures_path'), '/')."/{$operation}.json";
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $operation): array
    {
        $path = $this->path($operation);

        if (! is_file($path)) {
            throw new RuntimeException("Sem fixture para a operação {$operation}: execução de dry-run bloqueada sem inventar resultado.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture inválida para a operação {$operation}.");
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    public function availableOperations(): array
    {
        $dir = rtrim((string) config('monitoring.fixtures_path'), '/');

        if (! is_dir($dir)) {
            return [];
        }

        return collect(scandir($dir))
            ->filter(fn ($f) => str_ends_with((string) $f, '.json'))
            ->map(fn ($f) => basename((string) $f, '.json'))
            ->values()
            ->all();
    }
}
