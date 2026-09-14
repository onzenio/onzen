<?php

namespace Database\Factories;

use App\Enums\MonitoringRunStatus;
use App\Models\Account;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MonitoringRun>
 */
class MonitoringRunFactory extends Factory
{
    protected $model = MonitoringRun::class;

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
            'trigger' => MonitoringRun::TRIGGER_MANUAL,
            'definition_key' => MonitoringDefinition::factory(),
            'operation_code' => 'CONSDECLARACAO13',
            'idempotency_key' => (string) Str::uuid(),
            'fencing_token' => 1,
            'status' => MonitoringRunStatus::Pending,
            'environment' => 'homologacao',
            'dry_run' => true,
            'protocol' => null,
            'eta' => null,
            'parameters' => null,
            'external_code' => null,
            'error_code' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => MonitoringRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => MonitoringRunStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function awaitingProtocol(string $protocol = 'PROTO-1'): static
    {
        return $this->state(fn () => [
            'status' => MonitoringRunStatus::AwaitingProtocol,
            'protocol' => $protocol,
            'started_at' => now(),
        ]);
    }

    public function automatic(): static
    {
        return $this->state(fn () => ['trigger' => MonitoringRun::TRIGGER_AUTOMATIC]);
    }
}
