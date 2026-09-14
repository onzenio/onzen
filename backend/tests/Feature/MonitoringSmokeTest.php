<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Integrations\Serpro\SerproFixtureProvider;
use App\Integrations\Serpro\SerproTransport;
use App\Jobs\ExecuteSerproJob;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringRun;
use App\Models\Plan;
use App\Services\EnrollmentService;
use App\Services\ExecutionProcessor;
use App\Services\MonitoringRunService;
use App\Services\MonitoringScheduler;
use App\Services\QueryQuotaService;
use App\Services\QuotaExhaustedException;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitoringSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fluxo_dry_run_ponta_a_ponta(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        Http::preventStrayRequests();
        Queue::fake();

        // 1. Escritório com plano de 1 consulta/mês, client associado.
        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['max_clients' => 5, 'monthly_query_volume' => 1])->id])->save();
        $account = $account->refresh();
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $client = Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => true, 'regime' => 'simples',
        ]);
        $enrollment = app(EnrollmentService::class)->create($account, $client->id, 'sitfis', $operator);

        // 2. Consulta disparada manualmente.
        $run = app(MonitoringScheduler::class)->triggerManual($account, $operator, 'sitfis', $client->id, 'SMOKE-1');
        $this->assertSame(MonitoringRun::ORIGIN_MANUAL, $run->origin);
        Queue::assertPushedOn('serpro', ExecuteSerproJob::class);

        // 3. Transporte desligado: resolve por fixture, sem chamada real.
        $dry = app(SerproTransport::class)->request('consultar-sitfis', [], ['platform_account' => $account]);
        $this->assertSame('dry-run', $dry['transport']);
        Http::assertSentCount(0);

        // 4. Processamento gera snapshot; mudança gera alerta visível.
        $processor = app(ExecutionProcessor::class);
        $run->transitionTo(MonitoringRun::RUNNING);
        $processor->processCompletedRun($run, ['situacao_fiscal' => 'regular', 'cnd_disponivel' => true]);

        $run2 = app(MonitoringRunService::class)->startOrGet($account, 'SMOKE-2', [
            'client_id' => $client->id, 'definition_code' => 'sitfis',
        ]);
        // Quota de 1 já consumida pelo SMOKE-1: automático também bloqueia.
        try {
            app(QueryQuotaService::class)->reserve($account, $run2, MonitoringRun::ORIGIN_AUTOMATIC);
            $this->fail('Quota esgotada deveria bloquear');
        } catch (QuotaExhaustedException $e) {
            $this->assertStringContainsString('Plan', $e->getMessage());
        }

        // 5. Snapshot e alerta visíveis na leitura da carteira.
        $this->actingAs($operator)->getJson("/api/monitoring/clients/{$client->id}/snapshots")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($operator)->getJson("/api/monitoring/clients/{$client->id}/cnd")
            ->assertOk()->assertJsonPath('situacao_fiscal', 'regular');

        $processor->processCompletedRun(
            MonitoringRun::factory()->create([
                'account_id' => $account->id, 'client_id' => $client->id,
                'definition_code' => 'sitfis', 'status' => MonitoringRun::RUNNING,
            ]),
            ['situacao_fiscal' => 'irregular', 'cnd_disponivel' => false],
        );
        $this->assertSame(1, MonitoringAlert::query()->withoutGlobalScopes()->where('status', 'open')->count());
        $this->actingAs($operator)->getJson('/api/monitoring/dashboard')->assertOk()
            ->assertJsonPath('open_alerts', 1)
            ->assertJsonPath('quota.remaining', 0);

        // 6. Fixture oficial usada no fluxo existe e é marcada dry-run.
        $fixture = app(SerproFixtureProvider::class)->load('consultar-sitfis');
        $this->assertTrue((bool) $fixture['dry_run']);
    }
}
