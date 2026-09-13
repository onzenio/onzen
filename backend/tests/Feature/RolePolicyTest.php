<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RolePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_manage_platform_and_own_account(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::SuperAdmin]);
        CurrentAccount::set($account->id);
        $gate = Gate::forUser($admin);

        $this->assertTrue($gate->allows('create', Account::class));
        $this->assertTrue($gate->allows('viewAny', Account::class));
        $this->assertTrue($gate->allows('viewAny', Plan::class));
        $this->assertTrue($gate->allows('create', Plan::class));
        $this->assertTrue($gate->allows('create', Client::class));
        $this->assertTrue($gate->allows('create', Invitation::class));
        $this->assertTrue($gate->allows('viewAny', AuditLog::class));
    }

    public function test_admin_manages_own_account_but_not_platform(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        CurrentAccount::set($account->id);
        $gate = Gate::forUser($admin);

        $this->assertTrue($gate->allows('create', Client::class));
        $this->assertTrue($gate->allows('view', Client::factory()->for($account, 'account')->create()));
        $this->assertTrue($gate->allows('create', Invitation::class));
        $this->assertTrue($gate->allows('viewAny', AuditLog::class));

        $this->assertFalse($gate->allows('create', Account::class));
        $this->assertFalse($gate->allows('viewAny', Account::class));
        $this->assertFalse($gate->allows('viewAny', Plan::class));
        $this->assertFalse($gate->allows('create', Plan::class));
    }

    public function test_admin_cannot_reach_clients_of_another_account(): void
    {
        $mine = $this->createAccount();
        $other = $this->createAccount();
        $admin = $this->createUser($mine, ['role' => UserRole::Admin]);
        CurrentAccount::set($mine->id);
        $gate = Gate::forUser($admin);

        $foreign = Client::factory()->for($other, 'account')->create();

        $this->assertFalse($gate->allows('view', $foreign));
        $this->assertFalse($gate->allows('update', $foreign));
        $this->assertFalse($gate->allows('delete', $foreign));
    }

    public function test_operator_crud_clients_but_no_admin_actions(): void
    {
        $account = $this->createAccount();
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        CurrentAccount::set($account->id);
        $gate = Gate::forUser($operator);

        $client = Client::factory()->for($account, 'account')->create();

        $this->assertTrue($gate->allows('create', Client::class));
        $this->assertTrue($gate->allows('view', $client));
        $this->assertTrue($gate->allows('update', $client));
        $this->assertTrue($gate->allows('delete', $client));

        $this->assertFalse($gate->allows('create', Invitation::class));
        $this->assertFalse($gate->allows('viewAny', AuditLog::class));
        $this->assertFalse($gate->allows('create', Account::class));
        $this->assertFalse($gate->allows('create', Plan::class));
    }

    public function test_user_is_read_only_on_clients_and_denied_elsewhere(): void
    {
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::User]);
        CurrentAccount::set($account->id);
        $gate = Gate::forUser($user);

        $client = Client::factory()->for($account, 'account')->create();

        $this->assertTrue($gate->allows('viewAny', Client::class));
        $this->assertTrue($gate->allows('view', $client));

        $this->assertFalse($gate->allows('create', Client::class));
        $this->assertFalse($gate->allows('update', $client));
        $this->assertFalse($gate->allows('delete', $client));
        $this->assertFalse($gate->allows('create', Invitation::class));
        $this->assertFalse($gate->allows('viewAny', AuditLog::class));
    }

    public function test_later_phase_routes_do_not_exist_yet(): void
    {
        // Fase D implementada: /api/accounts, /api/onboarding e
        // /api/invitations existem (guest recebe 401 nas protegidas).
        // Fases E–G ainda não: sem plans/switch/clients/audit.
        // Ausência = 404, não 403.
        $this->postJson('/api/accounts', [])->assertUnauthorized();
        $this->getJson('/api/invitations')->assertUnauthorized();
        $this->getJson('/api/plans')->assertNotFound();
        $this->postJson('/api/switch', [])->assertNotFound();
    }
}
