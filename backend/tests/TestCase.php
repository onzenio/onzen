<?php

namespace Tests;

use App\Models\Account;
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
     * @param  array<string, mixed>  $attributes
     */
    protected function createAccount(array $attributes = []): Account
    {
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
