<?php

namespace App\Services;

use App\Models\MonitoringDefinition;
use RuntimeException;

class MonitoringCatalogService
{
    /**
     * @return array{type: string, operation: string}
     */
    public function resolveExecutableOperation(string $definitionCode): array
    {
        $definition = MonitoringDefinition::query()->where('code', $definitionCode)->first();

        if ($definition === null) {
            throw new RuntimeException("Definição desconhecida: {$definitionCode}.");
        }

        if (! $definition->isAvailable()) {
            throw new RuntimeException("Definição {$definitionCode} indisponível ({$definition->availability}).");
        }

        $operation = $definition->executableOperation();

        if ($operation === null) {
            throw new RuntimeException("Definição {$definitionCode} sem operação executável.");
        }

        return $operation;
    }

    /**
     * @return list<string>
     */
    public function procurationAllowlist(): array
    {
        return \Database\Seeders\MonitoringDefinitionSeeder::procurationAllowlist();
    }
}
