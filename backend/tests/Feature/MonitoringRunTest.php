<?php

namespace Tests\Feature;

use App\Models\MonitoringRun;
use App\Services\MonitoringRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonitoringRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_runs', [
            'id', 'account_id', 'client_id', 'definition_code', 'status',
            'idempotency_key', 'fencing_token', 'origin', 'protocol',
        ]));
        $this->assertTrue(Schema::hasColumns('monitoring_attempts', [
            'id', 'monitoring_run_id', 'attempt_number', 'outcome',
        ]));
    }

    public function test_transicoes_validas_e_invalidas(): void
    {
        $run = MonitoringRun::factory()->create(['status' => MonitoringRun::PENDING]);

        $run->transitionTo(MonitoringRun::RUNNING);
        $run->transitionTo(MonitoringRun::AWAITING_PROTOCOL);
        $run->transitionTo(MonitoringRun::RUNNING);
        $run->transitionTo(MonitoringRun::COMPLETED);

        $this->assertSame(MonitoringRun::COMPLETED, $run->refresh()->status);
        $this->assertTrue($run->isTerminal());

        try {
            $run->transitionTo(MonitoringRun::RUNNING);
            $this->fail('Transição de estado terminal deveria falhar');
        } catch (\InvalidArgumentException) {
        }
    }

    public function test_repeticao_mesma_chave_nao_duplica(): void
    {
        $account = $this->createAccount();
        $service = app(MonitoringRunService::class);

        $first = $service->startOrGet($account, 'KEY-1', ['definition_code' => 'sitfis']);
        $second = $service->startOrGet($account, 'KEY-1', ['definition_code' => 'sitfis']);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, MonitoringRun::query()->withoutGlobalScopes()->where('account_id', $account->id)->count());
    }

    public function test_execucao_superada_e_descartada(): void
    {
        $account = $this->createAccount();
        $service = app(MonitoringRunService::class);

        $stale = $service->startOrGet($account, 'STALE', ['definition_code' => 'sitfis', 'fencing_token' => 1]);
        $fresh = $service->startOrGet($account, 'FRESH', ['definition_code' => 'sitfis', 'fencing_token' => 3]);

        $this->assertTrue($service->discardIfStale($stale, 3));
        $this->assertSame(MonitoringRun::EXPIRED, $stale->refresh()->status);

        $this->assertFalse($service->discardIfStale($fresh, 3));
        $this->assertSame(MonitoringRun::PENDING, $fresh->refresh()->status);
    }
}
