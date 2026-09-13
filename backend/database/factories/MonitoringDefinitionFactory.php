<?php

namespace Database\Factories;

use App\Models\MonitoringDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MonitoringDefinition>
 */
class MonitoringDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'definition-'.Str::lower(Str::random(10)),
            'name' => fake()->words(3, true),
            'category' => 'Declarações',
            'system' => 'Integra Contador',
            'description' => null,
            'version' => MonitoringDefinition::CATALOG_VERSION,
            'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
            'strategy' => MonitoringDefinition::STRATEGY_POLLING,
            'default_enabled' => false,
            'is_active' => true,
            'requires_procuracao' => false,
            'operations' => null,
            'procuration_codes' => null,
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
            'services' => null,
        ];
    }

    public function prospeccao(): static
    {
        return $this->state(fn () => [
            'availability' => MonitoringDefinition::AVAILABILITY_PROSPECCAO,
            'operations' => null,
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn () => [
            'availability' => 'unavailable',
            'operations' => null,
        ]);
    }
}
