<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Models\Plan;
use App\Services\EnrollmentService;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonitoringEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithPlan(int $maxClients = 10): Account
    {
        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['max_clients' => $maxClients])->id])->save();

        return $account->refresh();
    }

    private function enabledClient($account, array $attrs = []): Client
    {
        return Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => true,
            'regime' => 'simples',
            ...$attrs,
        ]);
    }

    public function test_criacao_client_elegivel(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $account = $this->accountWithPlan();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = $this->enabledClient($account);

        $enrollment = app(EnrollmentService::class)->create($account, $client->id, 'pagamentos', $actor);

        $this->assertSame(MonitoringEnrollment::ACTIVE, $enrollment->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitoring_enrollment.created']);
    }

    public function test_recusas(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $account = $this->accountWithPlan();
        $service = app(EnrollmentService::class);

        // Monitoring Status inativo.
        $inactive = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => false]);
        try {
            $service->create($account, $inactive->id, 'pagamentos');
            $this->fail('Client inativo deveria ser recusado');
        } catch (ValidationException) {
        }

        // Definição indisponível / em prospecção.
        $client = $this->enabledClient($account);
        foreach (['sicalc', 'e-processo'] as $code) {
            try {
                $service->create($account, $client->id, $code);
                $this->fail("{$code} deveria ser recusada");
            } catch (ValidationException) {
            }
        }

        // Regime inelegível.
        $mei = $this->enabledClient($account, ['regime' => 'mei']);
        try {
            $service->create($account, $mei->id, 'defis');
            $this->fail('Regime inelegível deveria ser recusado');
        } catch (ValidationException) {
        }

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_duplicada_e_impedida_e_pausa_versiona(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $account = $this->accountWithPlan();
        $service = app(EnrollmentService::class);
        $client = $this->enabledClient($account);

        $enrollment = $service->create($account, $client->id, 'pagamentos');

        try {
            $service->create($account, $client->id, 'pagamentos');
            $this->fail('Duplicada deveria ser impedida');
        } catch (ValidationException) {
        }

        $enrollment->pause('outorga pendente');
        $this->assertSame(MonitoringEnrollment::PAUSED, $enrollment->refresh()->status);
        $this->assertSame('outorga pendente', $enrollment->refresh()->pause_reason);
        $this->assertSame(2, $enrollment->refresh()->version);

        $enrollment->resume();
        $this->assertSame(MonitoringEnrollment::ACTIVE, $enrollment->refresh()->status);
    }

    public function test_limite_do_plan(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $account = $this->accountWithPlan(1);
        $service = app(EnrollmentService::class);

        $service->create($account, $this->enabledClient($account)->id, 'pagamentos');

        try {
            $service->create($account, $this->enabledClient($account)->id, 'sitfis');
            $this->fail('Limite do Plan deveria recusar');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Plan', json_encode($e->errors()));
        }
    }

    public function test_busca_por_nome_e_cnpj_isolada_por_account(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $account = $this->accountWithPlan();
        $other = $this->accountWithPlan();
        $service = app(EnrollmentService::class);

        $mine = $this->enabledClient($account, ['razao_social' => 'Padaria Pão Dourado', 'cnpj' => '11111111000111']);
        $service->create($account, $mine->id, 'pagamentos');
        $theirs = $this->enabledClient($other, ['razao_social' => 'Padaria Pão Dourado', 'cnpj' => '22222222000122']);
        $service->create($other, $theirs->id, 'pagamentos');

        $byName = $service->search($account, 'Dourado');
        $this->assertSame(1, $byName->total());
        $this->assertTrue($byName->first()->client->is($mine));

        $byCnpj = $service->search($account, '22222222000122');
        $this->assertSame(0, $byCnpj->total());
    }

    public function test_client_fora_da_account_responde_404(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $account = $this->accountWithPlan();
        $other = $this->accountWithPlan();
        $user = $this->createUser($account, ['role' => UserRole::Operator]);
        $foreign = $this->enabledClient($other);

        $this->actingAs($user)->postJson('/api/monitoring/enrollments', [
            'client_id' => $foreign->id,
            'definition_code' => 'pagamentos',
        ])->assertNotFound();

        $this->actingAs($user)->getJson('/api/monitoring/enrollments/999999')->assertNotFound();
    }
}
