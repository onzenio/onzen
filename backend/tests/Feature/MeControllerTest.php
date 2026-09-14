<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_authenticated_user_receives_own_user_account_and_role(): void
    {
        $account = $this->createAccount(['name' => 'Acme', 'profile' => AccountProfile::B, 'plan_id' => null]);
        $user = $this->createUser($account, [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($user)->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Ada')
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.role', UserRole::Admin->value)
            ->assertJsonPath('account.id', $account->id)
            ->assertJsonPath('account.name', 'Acme')
            ->assertJsonPath('account.profile', AccountProfile::B->value)
            ->assertJsonPath('account.plan', null)
            ->assertJsonPath('acting_as', null);
    }

    public function test_me_includes_plan_when_account_has_one(): void
    {
        $plan = Plan::factory()->create(['name' => 'Básico']);
        $account = $this->createAccount(['plan_id' => $plan->id]);
        $user = $this->createUser($account);

        $this->actingAs($user)->getJson('/api/me')->assertOk()
            ->assertJsonPath('account.id', $account->id)
            ->assertJsonPath('account.plan.id', $plan->id)
            ->assertJsonPath('account.plan.name', 'Básico');
    }

    public function test_super_admin_switch_is_reflected_in_acting_as(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
        $target = $this->createAccount();

        $this->actingAs($admin)->withSession(['switch_account_id' => $target->id])
            ->withHeaders(['Origin' => 'http://localhost:3000'])
            ->getJson('/api/me')->assertOk()
            ->assertJsonPath('account.id', $target->id)
            ->assertJsonPath('acting_as.account_id', $target->id);
    }
}
