<?php

namespace Database\Factories;

use App\Enums\SerproActionStatus;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SerproServiceRequest>
 */
class SerproServiceRequestFactory extends Factory
{
    protected $model = SerproServiceRequest::class;

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
            'enrollment_id' => null,
            'installment_id' => null,
            'operation_code' => 'GERARDAS12',
            'modality' => null,
            'idempotency_key' => 'action-'.Str::lower(Str::random(20)),
            'status' => SerproActionStatus::Pending,
            'protocol' => null,
            'document_ref' => null,
            'parameters' => ['periodo_apuracao' => '202601'],
            'metadata' => null,
            'requested_by_user_id' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['status' => SerproActionStatus::Succeeded]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => SerproActionStatus::Rejected]);
    }
}
