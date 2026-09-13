<?php

namespace Database\Factories;

use App\Enums\AccountProfile;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'profile' => AccountProfile::B,
            'plan_id' => null,
        ];
    }
}
