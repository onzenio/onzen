<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringEnrollment>
 */
class MonitoringEnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'definition_code' => 'sitfis',
            'status' => MonitoringEnrollment::ACTIVE,
            'pause_reason' => null,
            'version' => 1,
        ];
    }
}
