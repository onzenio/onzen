<?php

namespace Database\Factories;

use App\Models\MonitoringDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringDefinition>
 */
class MonitoringDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('def-????'),
            'family' => 'Pagamentos',
            'name' => fake()->words(3, true),
            'catalog_version' => '1',
            'availability' => MonitoringDefinition::AVAILABLE,
            'strategy' => 'snapshot',
            'person_types' => ['PJ'],
            'regimes' => ['simples'],
            'required_services' => ['PAG-CONS'],
            'operations' => [['type' => 'consultar', 'operation' => 'consultar-pagamentos']],
            'automatic' => true,
            'unavailability_reason' => null,
        ];
    }
}
