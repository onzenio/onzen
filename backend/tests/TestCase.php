<?php

namespace Tests;

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        CurrentAccount::clear();

        parent::tearDown();
    }

    /**
     * Create an Account for tests. Kept stable for reuse by later blocks.
     *
     * Every Account gets a Plan unless one is given (or explicitly nulled),
     * mirroring onboarding, which assigns the default Plan on creation.
     * Reuses the seeded default Plan when one exists so plan counts stay
     * stable; only falls back to creating a Plan when none exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createAccount(array $attributes = []): Account
    {
        if (! array_key_exists('plan_id', $attributes)) {
            $attributes['plan_id'] = Plan::query()->where('is_default', true)->first()?->getKey()
                ?? Plan::query()->first()?->getKey()
                ?? Plan::factory()->create()->getKey();
        }

        return Account::factory()->create($attributes);
    }

    /**
     * Create a User bound to an Account. Creates an Account when none is given.
     * Kept stable for reuse by later blocks.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createUser(?Account $account = null, array $attributes = []): User
    {
        return User::factory()
            ->for($account ?? $this->createAccount(), 'account')
            ->create($attributes);
    }
}
