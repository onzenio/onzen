<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Raw tokens indexed by their sha256 hash, kept in memory only.
     *
     * @var array<string, string>
     */
    protected static array $rawTokens = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);
        $hash = hash('sha256', $token);
        static::$rawTokens[$hash] = $token;

        return [
            'account_id' => Account::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::User,
            'token_hash' => $hash,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'invited_by_user_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invitation $invitation): void {
            $hash = $invitation->getAttribute('token_hash');

            if (is_string($hash) && isset(static::$rawTokens[$hash])) {
                $invitation->setAttribute('token', static::$rawTokens[$hash]);
                $invitation->syncOriginal();
                unset(static::$rawTokens[$hash]);
            }
        });
    }
}
