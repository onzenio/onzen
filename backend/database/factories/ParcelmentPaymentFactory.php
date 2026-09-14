<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\ParcelmentInstallment;
use App\Models\ParcelmentPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParcelmentPayment>
 */
class ParcelmentPaymentFactory extends Factory
{
    protected $model = ParcelmentPayment::class;

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
            'installment_id' => ParcelmentInstallment::factory(),
            'external_id' => 'PAG-'.Str::upper(Str::random(6)),
            'status' => 'pago',
            'amount' => 100.04,
            'paid_at' => now()->subDay(),
            'receipt_ref' => null,
            'provenance' => 'serpro',
            'metadata' => null,
        ];
    }
}
