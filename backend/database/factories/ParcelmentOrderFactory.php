<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\ParcelmentOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParcelmentOrder>
 */
class ParcelmentOrderFactory extends Factory
{
    protected $model = ParcelmentOrder::class;

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
            'modality' => 'PARCSN',
            'external_id' => 'PARC-'.Str::upper(Str::random(6)),
            'status' => 'ativo',
            'installments_count' => 12,
            'total_amount' => 1200.50,
            'competence' => null,
            'provenance' => 'serpro',
            'operation_code' => 'PEDIDOSPARC163',
            'metadata' => null,
        ];
    }
}
