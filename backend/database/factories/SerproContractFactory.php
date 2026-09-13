<?php

namespace Database\Factories;

use App\Models\SerproContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproContract>
 */
class SerproContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment' => 'homologacao',
            'credential_ref' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function production(): static
    {
        return $this->state(fn () => ['environment' => 'producao']);
    }
}
