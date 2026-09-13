<?php

namespace Database\Factories;

use App\Models\SerproSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproSettings>
 */
class SerproSettingsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment' => SerproSettings::DEFAULT_ENVIRONMENT,
            'transport_approved' => false,
            'transport_approved_at' => null,
            'transport_approved_by_user_id' => null,
        ];
    }
}
