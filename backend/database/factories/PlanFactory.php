<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'price_cents' => 0,
            'max_users' => 3,
            'max_clients' => 10,
            'modules' => ['clients'],
            'monthly_query_volume' => 100,
            'is_default' => false,
        ];
    }
}
