<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountCertificate>
 */
class AccountCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'vault_ref' => 'secret:cert-'.Str::uuid(),
            'holder_name' => fake()->name(),
            'thumbprint' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addYear(),
            'uploaded_by_user_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
