<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringChange;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringChange>
 */
class MonitoringChangeFactory extends Factory
{
    protected $model = MonitoringChange::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'enrollment_id' => MonitoringEnrollment::factory(),
            'client_id' => Client::factory(),
            'snapshot_id' => MonitoringSnapshot::factory(),
            'previous_snapshot_id' => null,
            'run_id' => null,
            'operation_code' => 'RELATORIOSITFIS92',
            'kind' => MonitoringChange::KIND_CHANGED,
            'data' => ['before' => ['situacao' => 'regular'], 'after' => ['situacao' => 'irregular']],
            'created_at' => now(),
        ];
    }
}
