<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{cnpj: string, razao_social: string, regime: string, contador_responsavel: string}
     */
    protected function clientPayload(array $overrides = []): array
    {
        return array_merge([
            'cnpj' => '11222333000181',
            'razao_social' => 'Empresa Auditada LTDA',
            'regime' => 'simples',
            'contador_responsavel' => 'Contador Audit',
        ], $overrides);
    }

    public function test_record_persists_all_fields_and_returns_model(): void
    {
        $actor = $this->createUser();
        $origin = $actor->account;
        $target = $this->createAccount();

        $service = app(AuditService::class);
        $log = $service->record($actor, $origin->id, $target->id, 'account.created', ['ip' => '127.0.0.1']);

        $this->assertNotNull($log);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'actor_user_id' => $actor->id,
            'origin_account_id' => $origin->id,
            'target_account_id' => $target->id,
            'action' => 'account.created',
        ]);
        $this->assertSame(['ip' => '127.0.0.1'], $log->refresh()->metadata);
        $this->assertNotNull($log->created_at);
        $this->assertNull($log->getAttribute('updated_at'));
    }

    public function test_record_returns_null_without_throwing_on_db_failure(): void
    {
        Log::spy();

        $service = app(AuditService::class);

        // Sem origem resolvível: viola o NOT NULL — best-effort retorna null.
        $this->assertNull($service->record(null, null, null, 'test.broken'));

        // Origem inexistente: viola a FK — best-effort retorna null.
        $actor = $this->createUser();
        $this->assertNull($service->record($actor, 999999, null, 'test.broken'));

        Log::shouldHaveReceived('warning');
    }

    public function test_observer_failure_does_not_break_client_creation(): void
    {
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('audit down'));
        $this->app->instance(AuditService::class, $audit);

        $account = $this->createAccount([
            'plan_id' => Plan::factory()->create(['max_clients' => 10])->id,
        ]);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->postJson('/api/clients', $this->clientPayload())
            ->assertCreated()
            ->assertJsonPath('cnpj', '11222333000181');

        $this->assertDatabaseHas('clients', [
            'account_id' => $account->id,
            'cnpj' => '11222333000181',
        ]);
    }

    public function test_model_lifecycle_events_are_audited(): void
    {
        $owner = $this->createAccount();
        $superAdmin = $this->createUser($owner, ['role' => UserRole::SuperAdmin]);
        $this->actingAs($superAdmin);

        $account = Account::factory()->create();
        $user = User::factory()->for($account, 'account')->create();
        $invitation = Invitation::factory()->for($account, 'account')->create();
        $plan = Plan::factory()->create();
        $client = Client::factory()->for($account, 'account')->create();

        // origin = conta do ator (CurrentAccount é null fora do HTTP);
        // target = a própria Account criada (ela é o tenant).
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'origin_account_id' => $owner->id,
            'target_account_id' => $account->id,
            'action' => 'account.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'origin_account_id' => $owner->id,
            'target_account_id' => $account->id,
            'action' => 'user.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'origin_account_id' => $owner->id,
            'target_account_id' => $account->id,
            'action' => 'invitation.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'origin_account_id' => $owner->id,
            'action' => 'plan.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'origin_account_id' => $owner->id,
            'target_account_id' => $account->id,
            'action' => 'client.created',
        ]);

        // updated + deleted também geram linhas.
        $client->update(['regime' => 'lucro_real']);
        $clientId = $client->id;
        $client->delete();

        $this->assertDatabaseHas('audit_logs', ['action' => 'client.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.deleted']);
        $this->assertDatabaseMissing('clients', ['id' => $clientId]);
    }

    public function test_login_and_logout_are_audited(): void
    {
        $user = $this->createUser(attributes: ['email' => 'audit-auth@example.com']);

        $login = $this->postJson('/login', ['email' => 'audit-auth@example.com', 'password' => 'password']);
        $login->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'origin_account_id' => $user->account_id,
            'action' => 'auth.login',
        ]);

        $cookies = [];
        $xsrf = null;
        foreach ($login->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $xsrf = $cookie->getValue();
            }
        }

        $this->withCredentials()->withUnencryptedCookies($cookies)->withHeaders([
            'X-XSRF-TOKEN' => $xsrf,
            'Origin' => 'http://localhost:3000',
        ])->postJson('/logout')->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'origin_account_id' => $user->account_id,
            'action' => 'auth.logout',
        ]);
    }

    public function test_plan_change_is_audited(): void
    {
        $owner = $this->createAccount();
        $superAdmin = $this->createUser($owner, ['role' => UserRole::SuperAdmin]);
        $account = $this->createAccount();
        $plan = Plan::factory()->create(['name' => 'Novo Plano']);

        $this->actingAs($superAdmin)
            ->patchJson("/api/accounts/{$account->id}/plan", ['plan_id' => $plan->id])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'origin_account_id' => $owner->id,
            'target_account_id' => $account->id,
            'action' => 'plan.changed',
        ]);

        $row = AuditLog::query()->where('action', 'plan.changed')->latest('id')->firstOrFail();
        $this->assertSame($plan->id, $row->metadata['plan_id'] ?? null);
    }
}
