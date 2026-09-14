<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountCertificate>
 */
class AccountCertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'pfx_ref' => 'secret:'.fake()->unique()->lexify('????????'),
            'password_ref' => 'secret:'.fake()->unique()->lexify('????????'),
            'holder_name' => fake()->company().' LTDA',
            'thumbprint' => fake()->unique()->sha1(),
            'expires_at' => now()->addYear(),
        ];
    }
}
