<?php

namespace Database\Factories;

use App\Models\MonitoringAttempt;
use App\Models\MonitoringRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringAttempt>
 */
class MonitoringAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'monitoring_run_id' => MonitoringRun::factory(),
            'attempt_number' => 1,
            'status_code' => 200,
            'outcome' => MonitoringAttempt::OUTCOME_SUCCESS,
            'response_summary' => ['ok' => true],
            'backoff_seconds' => null,
        ];
    }
}
