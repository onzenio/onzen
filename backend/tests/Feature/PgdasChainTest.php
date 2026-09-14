<?php

namespace Tests\Feature;

use App\Integrations\Serpro\SerproFixtureProvider;
use App\Jobs\ExecuteSerproJob;
use App\Models\Client;
use App\Models\MonitoringRun;
use App\Models\Plan;
use App\Services\PgdasChainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PgdasChainTest extends TestCase
{
    use RefreshDatabase;

    private function accountClient(): array
    {
        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['monthly_query_volume' => 100])->id])->save();
        $client = Client::factory()->for($account->refresh(), 'account')->create();

        return [$account->refresh(), $client];
    }

    public function test_encadeia_quando_indice_muda(): void
    {
        Queue::fake();
        [$account, $client] = $this->accountClient();
        $index = app(SerproFixtureProvider::class)->load('consultar-pgdasd-indice')['payload'];

        $enqueued = app(PgdasChainService::class)->chainFromIndex($account, $client, $index);

        // 2 períodos × (declaração + extrato).
        $this->assertCount(4, $enqueued);
        $this->assertSame(4, MonitoringRun::query()->withoutGlobalScopes()->count());
        Queue::assertPushedOn('serpro', ExecuteSerproJob::class);
    }

    public function test_sem_novidade_nao_encadeia(): void
    {
        [$account, $client] = $this->accountClient();
        $service = app(PgdasChainService::class);
        $index = app(SerproFixtureProvider::class)->load('consultar-pgdasd-indice')['payload'];

        Queue::fake();
        $service->chainFromIndex($account, $client, $index);
        $this->assertSame(4, MonitoringRun::query()->withoutGlobalScopes()->count());

        Queue::fake();
        $this->assertSame([], $service->chainFromIndex($account, $client, $index));
        Queue::assertNothingPushed();
        $this->assertSame(4, MonitoringRun::query()->withoutGlobalScopes()->count());
    }

    public function test_novo_periodo_encadeia_so_a_novidade(): void
    {
        [$account, $client] = $this->accountClient();
        $service = app(PgdasChainService::class);

        $service->chainFromIndex($account, $client, ['periodos_disponiveis' => ['202501']]);
        $enqueued = $service->chainFromIndex($account, $client, ['periodos_disponiveis' => ['202501', '202502']]);

        $this->assertCount(2, $enqueued);
        $this->assertContains('consultar-pgdasd-declaracao:202502', $enqueued);
    }
}
