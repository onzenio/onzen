<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_account_prefers_valid_switch_target_for_super_admin_e_ignora_outros(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
        $target = $this->createAccount();

        $this->actingAs($admin)->withSession(['switch_account_id' => $target->id])
            ->getJson('/api/me')->assertOk()->assertJsonPath('account.id', $target->id);

        $user = $this->createUser();
        $this->actingAs($user)->withSession(['switch_account_id' => $target->id])
            ->getJson('/api/me')->assertOk()->assertJsonPath('account.id', $user->account_id);
    }

    public function test_invalid_switch_target_is_ignored_for_super_admin(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->withSession(['switch_account_id' => 999999])
            ->getJson('/api/me')->assertOk()
            ->assertJsonPath('account.id', $admin->account_id)
            ->assertJsonPath('acting_as', null);
    }
}
