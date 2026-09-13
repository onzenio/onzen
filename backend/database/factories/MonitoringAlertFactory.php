<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringAlert>
 */
class MonitoringAlertFactory extends Factory
{
    protected $model = MonitoringAlert::class;

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
            'change_id' => MonitoringChange::factory(),
            'status' => MonitoringAlert::STATUS_PENDING,
            'acknowledged_by_user_id' => null,
            'acknowledged_at' => null,
            'created_at' => now(),
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'status' => MonitoringAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);
    }
}
