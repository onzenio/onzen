<?php

namespace Database\Factories;

use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\Account;
use App\Models\Client;
use App\Models\PowerOfAttorney;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PowerOfAttorney>
 */
class PowerOfAttorneyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'code' => fake()->randomElement(ProcurationCatalog::allowlist()),
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addYear(),
            'metadata' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['valid_until' => now()->subDay()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => PowerOfAttorney::STATUS_INACTIVE]);
    }
}
