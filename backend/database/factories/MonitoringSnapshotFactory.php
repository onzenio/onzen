<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MonitoringSnapshot>
 */
class MonitoringSnapshotFactory extends Factory
{
    protected $model = MonitoringSnapshot::class;

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
            'run_id' => null,
            'operation_code' => 'RELATORIOSITFIS92',
            'family' => 'sitfis',
            'normalized' => true,
            'fingerprint' => hash('sha256', (string) Str::uuid()),
            'data' => ['situacao' => 'regular'],
            'freshness' => MonitoringSnapshot::FRESHNESS_FRESH,
            'completeness' => MonitoringSnapshot::COMPLETENESS_COMPLETE,
            'verified_at' => now(),
        ];
    }
}
