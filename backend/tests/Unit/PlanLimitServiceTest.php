<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Services\PlanLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_invite_allows_exactly_at_limit(): void
    {
        $service = new PlanLimitService;
        $account = Account::factory()->create([
            'plan_id' => Plan::factory()->create(['max_users' => 3])->id,
        ]);
        $this->createUser($account);
        $this->createUser($account);

        $this->assertNull($service->canInvite($account));
    }

    public function test_can_invite_blocks_when_users_plus_valid_pending_reach_max(): void
    {
        $service = new PlanLimitService;
        $account = Account::factory()->create([
            'plan_id' => Plan::factory()->create(['max_users' => 2])->id,
        ]);
        $this->createUser($account);
        Invitation::factory()->create(['account_id' => $account->id, 'accepted_at' => null]);

        $this->assertSame(
            'Limite de usuários do plano atingido. Faça upgrade do plano.',
            $service->canInvite($account)
        );
    }

    public function test_can_invite_ignores_expired_invitations(): void
    {
        $service = new PlanLimitService;
        $account = Account::factory()->create([
            'plan_id' => Plan::factory()->create(['max_users' => 2])->id,
        ]);
        $this->createUser($account);
        Invitation::factory()->create([
            'account_id' => $account->id,
            'accepted_at' => null,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertNull($service->canInvite($account));
    }

    public function test_can_accept_excludes_the_invite_itself_at_exact_limit(): void
    {
        $service = new PlanLimitService;
        $account = Account::factory()->create([
            'plan_id' => Plan::factory()->create(['max_users' => 3])->id,
        ]);
        $this->createUser($account);
        $this->createUser($account);
        $invitation = Invitation::factory()->create(['account_id' => $account->id, 'accepted_at' => null]);

        $this->assertNull($service->canAccept($invitation));
    }

    public function test_can_accept_blocks_when_full_without_counting_itself(): void
    {
        $service = new PlanLimitService;
        $account = Account::factory()->create([
            'plan_id' => Plan::factory()->create(['max_users' => 2])->id,
        ]);
        $this->createUser($account);
        $this->createUser($account);
        $invitation = Invitation::factory()->create(['account_id' => $account->id, 'accepted_at' => null]);

        $this->assertNotNull($service->canAccept($invitation));
    }

    public function test_can_create_client_respects_max_clients(): void
    {
        $service = new PlanLimitService;
        $account = Account::factory()->create([
            'plan_id' => Plan::factory()->create(['max_clients' => 1])->id,
        ]);

        $this->assertNull($service->canCreateClient($account));

        Client::factory()->create(['account_id' => $account->id]);

        $this->assertNotNull($service->canCreateClient($account));
    }

    public function test_can_access_module_checks_plan_modules(): void
    {
        $service = new PlanLimitService;
        $account = Account::factory()->create([
            'plan_id' => Plan::factory()->create(['modules' => ['clients']])->id,
        ]);

        $this->assertNull($service->canAccessModule($account, 'clients'));
        $this->assertNotNull($service->canAccessModule($account, 'fiscal'));
    }
}
