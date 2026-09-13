<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringEnrollment>
 */
class MonitoringEnrollmentFactory extends Factory
{
    protected $model = MonitoringEnrollment::class;

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
            'definition_id' => MonitoringDefinition::factory(),
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'pause_reason' => null,
            'version' => 1,
            'configuration' => null,
            'last_change_at' => now(),
        ];
    }

    public function paused(string $reason = 'outorga pendente'): static
    {
        return $this->state(fn () => [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => $reason,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => MonitoringEnrollment::STATUS_ENDED,
            'pause_reason' => null,
        ]);
    }
}
