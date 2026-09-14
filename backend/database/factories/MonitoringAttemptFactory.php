<?php

namespace Database\Factories;

use App\Enums\MonitoringRunStatus;
use App\Models\MonitoringAttempt;
use App\Models\MonitoringRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringAttempt>
 */
class MonitoringAttemptFactory extends Factory
{
    protected $model = MonitoringAttempt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => MonitoringRun::factory(),
            'attempt' => 1,
            'status' => MonitoringRunStatus::Pending,
            'response_code' => null,
            'classification' => null,
            'retry_after' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => MonitoringRunStatus::Completed,
            'response_code' => 200,
            'classification' => 'ok',
        ]);
    }

    public function transient(): static
    {
        return $this->state(fn () => [
            'status' => MonitoringRunStatus::Transient,
            'response_code' => 503,
            'classification' => 'transient_error',
        ]);
    }
}
