<?php

namespace Tests\Feature;

use App\Enums\MonitoringRunStatus;
use App\Enums\SerproActionStatus;
use App\Models\MonitoringRun;
use App\Models\SerproServiceRequest;
use App\Services\Monitoring\SerproRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SerproRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function recovery(): SerproRecovery
    {
        return app(SerproRecovery::class);
    }

    private function stale(string $table, int $id, int $minutes = 20): void
    {
        MonitoringRun::query()->getQuery()->getConnection()->table($table)
            ->where('id', $id)
            ->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    public function test_fresh_running_run_is_left_to_its_worker(): void
    {
        $run = MonitoringRun::factory()->create(['status' => MonitoringRunStatus::Running]);

        $recovered = $this->recovery()->recoverRun($run->refresh());

        $this->assertSame(MonitoringRunStatus::Running, $recovered->status);
    }

    public function test_stale_running_run_with_protocol_resumes_polling_only(): void
    {
        $run = MonitoringRun::factory()->create([
            'status' => MonitoringRunStatus::Running,
            'protocol' => 'PROTO-RESUME-1',
        ]);
        $this->stale('monitoring_runs', $run->id);

        $recovered = $this->recovery()->recoverRun($run->refresh());

        $this->assertSame(MonitoringRunStatus::AwaitingProtocol, $recovered->status);
        $this->assertSame('PROTO-RESUME-1', $recovered->protocol);
        $this->assertSame(SerproRecovery::ERROR_WORKER_INTERRUPTED, $recovered->error_code);
    }

    public function test_stale_running_run_without_protocol_is_closed_without_resending(): void
    {
        $run = MonitoringRun::factory()->create([
            'status' => MonitoringRunStatus::Running,
            'protocol' => null,
        ]);
        $this->stale('monitoring_runs', $run->id);

        $recovered = $this->recovery()->recoverRun($run->refresh());

        $this->assertSame(MonitoringRunStatus::Expired, $recovered->status);
        $this->assertSame(SerproRecovery::ERROR_WORKER_INTERRUPTED, $recovered->error_code);
        $this->assertNotNull($recovered->finished_at);
    }

    public function test_non_running_run_is_untouched(): void
    {
        $run = MonitoringRun::factory()->create(['status' => MonitoringRunStatus::Pending]);
        $this->stale('monitoring_runs', $run->id);

        $recovered = $this->recovery()->recoverRun($run->refresh());

        $this->assertSame(MonitoringRunStatus::Pending, $recovered->status);
    }

    public function test_fresh_running_action_is_left_to_its_worker(): void
    {
        $action = SerproServiceRequest::factory()->create(['status' => SerproActionStatus::Running]);

        $recovered = $this->recovery()->recoverAction($action->refresh());

        $this->assertSame(SerproActionStatus::Running, $recovered->status);
    }

    public function test_stale_running_action_with_protocol_resumes_polling_only(): void
    {
        $action = SerproServiceRequest::factory()->create([
            'status' => SerproActionStatus::Running,
            'protocol' => 'PROTO-ACTION-1',
        ]);
        $this->stale('serpro_service_requests', $action->id);

        $recovered = $this->recovery()->recoverAction($action->refresh());

        $this->assertSame(SerproActionStatus::Pending, $recovered->status);
        $this->assertSame('PROTO-ACTION-1', $recovered->protocol);
    }

    public function test_stale_running_action_without_protocol_is_closed_without_resending(): void
    {
        $action = SerproServiceRequest::factory()->create([
            'status' => SerproActionStatus::Running,
            'protocol' => null,
        ]);
        $this->stale('serpro_service_requests', $action->id);

        $recovered = $this->recovery()->recoverAction($action->refresh());

        $this->assertSame(SerproActionStatus::Expired, $recovered->status);
        $this->assertTrue($recovered->status->isTerminal());
        $this->assertSame(SerproRecovery::ERROR_WORKER_INTERRUPTED, $recovered->metadata['error_code'] ?? null);
    }

    public function test_processing_window_exceeds_queue_redelivery_so_active_work_is_never_abandoned(): void
    {
        $window = (int) config('monitoring.recovery.processing_window');
        $retryAfter = (int) config('queue.connections.serpro.retry_after');
        $timeout = (int) config('monitoring.transport.timeout');

        $this->assertGreaterThan($retryAfter, $window);
        $this->assertGreaterThan($timeout, $retryAfter);

        $run = MonitoringRun::factory()->create(['status' => MonitoringRunStatus::Running]);
        MonitoringRun::query()->getQuery()->getConnection()->table('monitoring_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subSeconds($retryAfter + 10)]);

        $recovered = $this->recovery()->recoverRun($run->refresh());

        $this->assertSame(MonitoringRunStatus::Running, $recovered->status);
    }
}
