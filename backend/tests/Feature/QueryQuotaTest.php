<?php

namespace Tests\Feature;

use App\Models\MonitoringQuotaUsage;
use App\Models\MonitoringRun;
use App\Models\Plan;
use App\Services\QueryQuotaService;
use App\Services\QuotaExhaustedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithVolume(int $volume): \App\Models\Account
    {
        $plan = Plan::factory()->create(['monthly_query_volume' => $volume]);
        $account = $this->createAccount();
        $account->forceFill(['plan_id' => $plan->id])->save();

        return $account->refresh();
    }

    public function test_consumo_desconta_do_volume(): void
    {
        $account = $this->accountWithVolume(2);
        $service = app(QueryQuotaService::class);

        $run = MonitoringRun::factory()->create(['account_id' => $account->id]);
        $service->reserve($account, $run);

        $balance = $service->balance($account);
        $this->assertSame(['volume' => 2, 'used' => 1, 'remaining' => 1, 'period' => now()->format('Y-m')], $balance);
    }

    public function test_volume_esgotado_bloqueia_sem_debito(): void
    {
        $account = $this->accountWithVolume(1);
        $service = app(QueryQuotaService::class);

        $service->reserve($account, MonitoringRun::factory()->create(['account_id' => $account->id]));

        try {
            $service->reserve($account, MonitoringRun::factory()->create(['account_id' => $account->id]));
            $this->fail('Deveria recusar com volume esgotado');
        } catch (QuotaExhaustedException $e) {
            $this->assertStringContainsString('Plan', $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }

        $this->assertSame(1, MonitoringQuotaUsage::query()->withoutGlobalScopes()->count());
    }

    public function test_mesma_execucao_nao_e_cobrada_duas_vezes(): void
    {
        $account = $this->accountWithVolume(1);
        $service = app(QueryQuotaService::class);
        $run = MonitoringRun::factory()->create(['account_id' => $account->id]);

        $service->reserve($account, $run);
        $service->reserve($account, $run);

        $this->assertSame(1, MonitoringQuotaUsage::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, $service->balance($account)['remaining']);
    }

    public function test_automatica_tambem_consome_e_sem_plano_bloqueia(): void
    {
        $account = $this->accountWithVolume(1);
        $service = app(QueryQuotaService::class);

        $service->reserve(
            $account,
            MonitoringRun::factory()->create(['account_id' => $account->id]),
            MonitoringRun::ORIGIN_AUTOMATIC,
        );

        $this->assertSame(1, $service->balance($account)['used']);

        $noPlan = $this->createAccount();

        try {
            $service->reserve($noPlan, MonitoringRun::factory()->create(['account_id' => $noPlan->id]));
            $this->fail('Conta sem Plan deveria ser bloqueada');
        } catch (QuotaExhaustedException) {
        }
    }
}
