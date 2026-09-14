<?php

namespace Database\Factories;

use App\Enums\AuthorDocumentType;
use App\Enums\AuthorStatus;
use App\Models\Account;
use App\Models\SerproRequestAuthor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerproRequestAuthor>
 */
class SerproRequestAuthorFactory extends Factory
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
            'document' => fake()->unique()->numerify('###########'),
            'document_type' => AuthorDocumentType::Pf,
            'name' => fake()->name(),
            'status' => AuthorStatus::Active,
            'certificate_thumbprint' => null,
            'certificate_expires_at' => null,
            'metadata' => [],
        ];
    }

    public function ineligible(): static
    {
        return $this->state(fn () => ['status' => AuthorStatus::Ineligible]);
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'document' => fake()->unique()->numerify('##############'),
            'document_type' => AuthorDocumentType::Pj,
        ]);
    }
}
