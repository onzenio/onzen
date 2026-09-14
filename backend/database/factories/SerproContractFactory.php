<?php

namespace Database\Factories;

use App\Models\SerproContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproContract>
 */
class SerproContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'environment' => SerproContract::ENV_HOMOLOGACAO,
            'consumer_key_ref' => 'secret:'.fake()->unique()->lexify('????????'),
            'consumer_secret_ref' => 'secret:'.fake()->unique()->lexify('????????'),
            'transport_approved' => false,
        ];
    }
}
