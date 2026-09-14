<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ExecuteSerproJob;
use App\Models\MonitoringRun;
use App\Models\Plan;
use App\Services\MonitoringScheduler;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitoringSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithVolume(int $volume): \App\Models\Account
    {
        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['monthly_query_volume' => $volume])->id])->save();

        return $account->refresh();
    }

    public function test_disparo_manual_responde_com_execucao_e_enfileira(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        Queue::fake();

        $account = $this->accountWithVolume(10);
        $actor = $this->createUser($account, ['role' => UserRole::Operator]);

        $run = app(MonitoringScheduler::class)->triggerManual($account, $actor, 'sitfis', null, 'MAN-1');

        $this->assertSame(MonitoringRun::ORIGIN_MANUAL, $run->origin);
        $this->assertSame('sitfis', $run->definition_code);
        Queue::assertPushedOn('serpro', ExecuteSerproJob::class);
    }

    public function test_ciclo_automatico_so_definicoes_automaticas_com_origem_automatica(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        Queue::fake();

        $account = $this->accountWithVolume(100);

        $dispatched = app(MonitoringScheduler::class)->runAutomaticCycle($account);

        $this->assertGreaterThan(0, $dispatched);

        $runs = MonitoringRun::query()->withoutGlobalScopes()->where('account_id', $account->id)->get();
        $this->assertTrue($runs->every(fn ($r) => $r->origin === MonitoringRun::ORIGIN_AUTOMATIC));
        // caixa-postal e dte não são automáticas; sicalc/e-processo indisponíveis.
        $this->assertFalse($runs->contains(fn ($r) => $r->definition_code === 'caixa-postal'));
        $this->assertFalse($runs->contains(fn ($r) => $r->definition_code === 'sicalc'));
        Queue::assertPushedOn('serpro', ExecuteSerproJob::class);
    }

    public function test_user_nao_dispara_consulta(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $account = $this->accountWithVolume(10);
        $actor = $this->createUser($account, ['role' => UserRole::User]);

        $this->expectException(AuthorizationException::class);

        app(MonitoringScheduler::class)->triggerManual($account, $actor, 'sitfis', null, 'MAN-X');
    }

    public function test_ciclo_sem_quota_pula_sem_quebrar(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        Queue::fake();

        $account = $this->accountWithVolume(1);

        $first = app(MonitoringScheduler::class)->runAutomaticCycle($account);
        $this->assertSame(1, $first);

        $second = app(MonitoringScheduler::class)->runAutomaticCycle($account);
        // Idempotência: mesmas chaves, nada novo; sem exceção.
        $this->assertSame(0, $second);
    }
}
