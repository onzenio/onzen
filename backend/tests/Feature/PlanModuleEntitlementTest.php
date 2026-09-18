<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlanModuleEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithoutModules()
    {
        return $this->createAccount([
            'plan_id' => Plan::factory()->create(['modules' => []])->id,
        ]);
    }

    private function accountWithClientModule()
    {
        return $this->createAccount([
            'plan_id' => Plan::factory()->create(['modules' => ['clients']])->id,
        ]);
    }

    public function test_clients_read_is_denied_without_the_module(): void
    {
        $account = $this->accountWithoutModules();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/clients')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');
    }

    public function test_clients_write_is_denied_without_the_module_before_mutating(): void
    {
        $account = $this->accountWithoutModules();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->postJson('/api/clients', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');

        $this->assertDatabaseMissing('clients', ['account_id' => $account->id]);
    }

    public function test_clients_access_is_allowed_with_the_module(): void
    {
        $account = $this->accountWithClientModule();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/clients')->assertOk();
    }

    public function test_monitoring_read_is_denied_without_the_module(): void
    {
        $account = $this->accountWithoutModules();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/monitoring/enrollments')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');
    }

    public function test_monitoring_write_is_denied_without_the_module(): void
    {
        $account = $this->accountWithoutModules();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->postJson('/api/monitoring/enrollments', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');
    }

    public function test_direct_call_to_artifact_link_is_denied_without_the_module(): void
    {
        $account = $this->accountWithoutModules();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/monitoring/artifacts/ref-qualquer/url')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');
    }

    public function test_monitoring_access_is_allowed_with_the_module(): void
    {
        $account = $this->accountWithClientModule();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/monitoring/enrollments')->assertOk();
    }

    public function test_every_clients_and_monitoring_route_carries_module_entitlement(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/clients') && ! str_starts_with($uri, 'api/monitoring')) {
                continue;
            }
            $covered = false;
            foreach ($route->middleware() as $middleware) {
                if (str_starts_with((string) $middleware, 'plan.module')) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $unprotected[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $unprotected);
    }
}
