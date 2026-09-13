<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'origin_account_id' => Account::factory(),
            'target_account_id' => null,
            'action' => 'account.created',
            'metadata' => [],
        ];
    }
}
