<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_account_id_and_role_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['account_id', 'role']));
    }

    public function test_user_belongs_to_account(): void
    {
        $account = $this->createAccount();
        $user = $this->createUser($account);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'account_id' => $account->id]);
        $this->assertTrue($user->account->is($account));
        $this->assertTrue($account->users->contains($user));
    }

    public function test_user_role_defaults_to_user(): void
    {
        $user = $this->createUser();

        $this->assertSame(UserRole::User, $user->refresh()->role);
    }

    public function test_user_role_persists(): void
    {
        $user = $this->createUser(attributes: ['role' => UserRole::Admin]);

        $this->assertSame(UserRole::Admin, $user->refresh()->role);
    }
}
