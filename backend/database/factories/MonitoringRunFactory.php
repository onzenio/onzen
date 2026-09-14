<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\MonitoringRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringRun>
 */
class MonitoringRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => null,
            'definition_code' => 'sitfis',
            'status' => MonitoringRun::PENDING,
            'idempotency_key' => fake()->unique()->lexify('run-????????'),
            'fencing_token' => 1,
            'origin' => MonitoringRun::ORIGIN_MANUAL,
            'triggered_by' => null,
        ];
    }
}
