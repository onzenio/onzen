<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_lists_plans_paginated(): void
    {
        Plan::factory()->count(3)->create();
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->getJson('/api/plans')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_non_super_admin_cannot_list_plans(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/plans')->assertForbidden();
    }

    public function test_guest_cannot_list_plans(): void
    {
        $this->getJson('/api/plans')->assertUnauthorized();
    }

    public function test_super_admin_creates_plan(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->postJson('/api/plans', [
            'name' => 'Empresarial',
            'price_cents' => 9990,
            'max_users' => 10,
            'max_clients' => 50,
            'modules' => ['clients'],
            'monthly_query_volume' => 1000,
            'is_default' => false,
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Empresarial');
        $this->assertDatabaseHas('plans', ['name' => 'Empresarial', 'max_clients' => 50]);
    }

    public function test_create_plan_validates_required_fields(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->postJson('/api/plans', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'name', 'price_cents', 'max_users', 'max_clients', 'modules', 'monthly_query_volume',
            ]);
    }

    public function test_non_super_admin_cannot_create_or_update_plan(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::Admin]);
        $plan = Plan::factory()->create();

        $this->actingAs($admin)->postJson('/api/plans', [
            'name' => 'X',
            'price_cents' => 0,
            'max_users' => 1,
            'max_clients' => 1,
            'modules' => ['clients'],
            'monthly_query_volume' => 10,
        ])->assertForbidden();

        $this->actingAs($admin)->patchJson("/api/plans/{$plan->id}", [
            'max_clients' => 99,
        ])->assertForbidden();
    }

    public function test_super_admin_updates_plan(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
        $plan = Plan::factory()->create(['name' => 'Antigo', 'max_clients' => 10]);

        $this->actingAs($admin)->patchJson("/api/plans/{$plan->id}", [
            'name' => 'Novo',
            'max_clients' => 25,
        ])->assertOk()->assertJsonPath('max_clients', 25);

        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'name' => 'Novo']);
    }

    public function test_setting_default_unmarks_other_plans(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
        $old = Plan::factory()->create(['is_default' => true]);
        $plan = Plan::factory()->create(['is_default' => false]);

        $this->actingAs($admin)->patchJson("/api/plans/{$plan->id}", [
            'is_default' => true,
        ])->assertOk();

        $this->assertTrue($plan->refresh()->is_default);
        $this->assertFalse($old->refresh()->is_default);
    }

    public function test_creating_default_plan_unmarks_other_plans(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
        $old = Plan::factory()->create(['is_default' => true]);

        $this->actingAs($admin)->postJson('/api/plans', [
            'name' => 'Novo Padrão',
            'price_cents' => 100,
            'max_users' => 5,
            'max_clients' => 20,
            'modules' => ['clients'],
            'monthly_query_volume' => 500,
            'is_default' => true,
        ])->assertCreated();

        $this->assertFalse($old->refresh()->is_default);
        $this->assertCount(1, Plan::query()->where('is_default', true)->get());
    }

    public function test_super_admin_swaps_account_plan_with_immediate_effect(): void
    {
        $basic = Plan::factory()->create(['max_clients' => 10]);
        $pro = Plan::factory()->create(['max_clients' => 100]);
        $account = $this->createAccount(['plan_id' => $basic->id]);
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->patchJson("/api/accounts/{$account->id}/plan", [
            'plan_id' => $pro->id,
        ])->assertOk()->assertJsonPath('plan.id', $pro->id);

        $this->assertTrue($account->refresh()->plan->is($pro));
    }

    public function test_account_b_admin_cannot_swap_plan(): void
    {
        $pro = Plan::factory()->create();
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->patchJson("/api/accounts/{$account->id}/plan", [
            'plan_id' => $pro->id,
        ])->assertForbidden();
    }

    public function test_swap_rejects_unknown_plan(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->patchJson("/api/accounts/{$account->id}/plan", [
            'plan_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors(['plan_id']);
    }
}
