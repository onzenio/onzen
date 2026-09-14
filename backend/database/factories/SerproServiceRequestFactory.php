<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\SerproServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproServiceRequest>
 */
class SerproServiceRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => fake()->unique()->lexify('emit-????????'),
            'status' => SerproServiceRequest::PENDING,
            'confirmed' => true,
        ];
    }
}
