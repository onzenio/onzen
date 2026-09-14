<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSerproActionJob;
use App\Jobs\ExecuteSerproJob;
use App\Models\MonitoringRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SerproJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_consulta_enfileirada_na_conexao_serpro(): void
    {
        Queue::fake();

        ExecuteSerproJob::dispatch(123);

        Queue::assertPushedOn('serpro', ExecuteSerproJob::class);
    }

    public function test_acao_enfileirada_na_conexao_serpro(): void
    {
        Queue::fake();

        ExecuteSerproActionJob::dispatch(123, true);

        Queue::assertPushedOn('serpro', ExecuteSerproActionJob::class);
    }

    public function test_tentativas_e_backoff(): void
    {
        $job = new ExecuteSerproJob(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 1800], $job->backoff());
        $this->assertSame([60, 300, 900, 1800], (new ExecuteSerproActionJob(1, true))->backoff());
        $this->assertSame('serpro', config('queue.connections.serpro.queue'));
    }

    public function test_handle_avanca_execucao_pendente(): void
    {
        $run = MonitoringRun::factory()->create(['status' => MonitoringRun::PENDING]);

        (new ExecuteSerproJob($run->id))->handle();

        $this->assertSame(MonitoringRun::RUNNING, $run->refresh()->status);
    }

    public function test_acao_sem_confirmacao_recusa(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ExecuteSerproActionJob(999, false))->handle();
    }
}
