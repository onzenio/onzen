<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\ParcelmentInstallment;
use App\Models\ParcelmentOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParcelmentInstallment>
 */
class ParcelmentInstallmentFactory extends Factory
{
    protected $model = ParcelmentInstallment::class;

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
            'order_id' => ParcelmentOrder::factory(),
            'external_id' => 'PARC-INST-'.Str::upper(Str::random(6)),
            'number' => 1,
            'status' => 'available',
            'amount' => 100.04,
            'due_date' => now()->addMonth()->startOfMonth(),
            'paid_at' => null,
            'guide_ref' => null,
            'provenance' => 'serpro',
            'metadata' => null,
        ];
    }
}
