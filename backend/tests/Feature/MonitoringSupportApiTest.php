<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringRun;
use App\Models\Plan;
use App\Services\AccountCertificateService;
use App\Services\EnrollmentService;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitoringSupportApiTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithPlan(): Account
    {
        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['max_clients' => 10, 'monthly_query_volume' => 10])->id])->save();

        return $account->refresh();
    }

    public function test_catalogo_expoe_disponibilidade(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $user = $this->createUser($this->accountWithPlan(), ['role' => UserRole::Operator]);

        $this->actingAs($user)->getJson('/api/monitoring/catalog')->assertOk()
            ->assertJsonPath('version', MonitoringDefinitionSeeder::CATALOG_VERSION)
            ->assertJsonCount(13, 'data');
    }

    public function test_trigger_manual_202_e_quota(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        Queue::fake();

        $account = $this->accountWithPlan();
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $client = Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => true, 'regime' => 'simples',
        ]);
        $enrollment = app(EnrollmentService::class)->create($account, $client->id, 'sitfis');

        $this->actingAs($operator)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/trigger", ['idempotency_key' => 'TRG-1'])
            ->assertStatus(202)
            ->assertJsonStructure(['run_id', 'status']);

        $this->assertSame(1, MonitoringRun::query()->withoutGlobalScopes()->count());
        Queue::assertPushedOn('serpro', ExecuteSerproJob::class);

        $user = $this->createUser($account, ['role' => UserRole::User]);
        $this->actingAs($user)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/trigger")
            ->assertForbidden();
    }

    public function test_certificado_mascarado_e_permissoes(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/account/certificate')->assertOk()
            ->assertJsonPath('configured', false);

        $response = $this->actingAs($admin)->putJson('/api/account/certificate', [
            'pfx_base64' => base64_encode('PFX-BIN'),
            'password' => 'segredo',
            'holder_name' => 'Empresa LTDA',
            'thumbprint' => str_repeat('a', 40),
            'expires_at' => now()->addYear()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('configured', true);

        $this->assertStringNotContainsString('PFX-BIN', (string) $response->getContent());
        $this->assertStringNotContainsString('segredo', (string) $response->getContent());

        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $this->actingAs($operator)->putJson('/api/account/certificate', [
            'pfx_base64' => base64_encode('X'),
            'password' => 'y',
            'holder_name' => 'Z',
            'thumbprint' => 't',
            'expires_at' => now()->addYear()->toIso8601String(),
        ])->assertForbidden();
    }

    public function test_autores_listagem_e_criacao(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        app(AccountCertificateService::class)->register($account, [
            'pfx_ref' => 'secret:X', 'password_ref' => 'secret:Y',
            'holder_name' => 'E', 'thumbprint' => str_repeat('b', 40),
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($admin)->postJson('/api/monitoring/authors', [
            'document' => '12345678901', 'name' => 'Procurador',
        ])->assertCreated()->assertJsonPath('eligible', true);

        $this->actingAs($admin)->getJson('/api/monitoring/authors')->assertOk()
            ->assertJsonCount(1, 'data');

        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $this->actingAs($operator)->postJson('/api/monitoring/authors', [
            'document' => '99999999999', 'name' => 'Outro',
        ])->assertForbidden();
    }
}
