<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproRequestAuthor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproRequestAuthor>
 */
class SerproRequestAuthorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'account_certificate_id' => AccountCertificate::factory(),
            'document' => fake()->unique()->numerify('###########'),
            'name' => fake()->name(),
            'status' => SerproRequestAuthor::STATUS_ACTIVE,
            'token_ref' => null,
            'token_expires_at' => null,
        ];
    }
}
