<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Enums\MonitoringRunStatus;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Services\Monitoring\SerproExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\FakeResultProjector;
use Tests\TestCase;

final class ExecuteSerproJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeResultProjector $projector;

    /**
     * @var list<string>
     */
    private array $tempDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->projector = new FakeResultProjector;
        $this->app->instance(ResultProjector::class, $this->projector);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_dispatch_uses_the_serpro_queue_and_connection(): void
    {
        Queue::fake();
        $run = MonitoringRun::factory()->create();

        ExecuteSerproJob::dispatch($run->id);

        Queue::assertPushedOn('serpro', ExecuteSerproJob::class);
        Queue::assertPushed(ExecuteSerproJob::class, function (ExecuteSerproJob $job) use ($run): bool {
            return $job->connection === 'serpro'
                && $job->queue === 'serpro'
                && $job->runId === $run->id
                && $job->tries === 8;
        });
    }

    public function test_attempts_and_backoff_come_from_config_and_the_documented_schedule(): void
    {
        $job = new ExecuteSerproJob(1);

        $this->assertSame(8, $job->tries);
        $this->assertSame([15, 60, 300, 900], $job->backoff());
    }

    public function test_handle_executes_the_run_identified_by_the_job(): void
    {
        $run = $this->claim($this->createAccount(), 'CONSDECLARACAO13', 'handle-key');

        $job = (new ExecuteSerproJob($run->id))->withFakeQueueInteractions();
        $job->handle(app(SerproExecutor::class));

        $run->refresh();

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);
        $this->assertSame(1, $this->projector->calls());
        $this->assertSame($run->id, $this->projector->projections[0]['run_id']);
        $job->assertNotReleased();
    }

    public function test_handle_releases_until_the_eta_when_awaiting_protocol(): void
    {
        $this->freezeTime();

        try {
            $eta = now()->addMinutes(15);
            $this->writeFixture('CONSDECLARACAO13', [
                'operation' => 'CONSDECLARACAO13',
                'dry_run' => true,
                'http_status' => 202,
                'body' => [
                    'protocol' => ['protocol_id' => 'PROTO-ETA', 'obtained' => false],
                    'eta' => $eta->toIso8601String(),
                ],
            ]);
            $run = $this->claim($this->createAccount(), 'CONSDECLARACAO13', 'eta-release-key');

            $job = (new ExecuteSerproJob($run->id))->withFakeQueueInteractions();
            $job->handle(app(SerproExecutor::class));

            $this->assertSame(MonitoringRunStatus::AwaitingProtocol, $run->refresh()->status);
            $job->assertReleased(900);
        } finally {
            $this->travelBack();
        }
    }

    public function test_handle_releases_with_backoff_when_awaiting_protocol_without_eta(): void
    {
        $run = $this->claim($this->createAccount(), 'SOLICITARPROTOCOLO91', 'protocol-release-key');

        $job = (new ExecuteSerproJob($run->id))->withFakeQueueInteractions();
        $job->handle(app(SerproExecutor::class));

        $this->assertSame(MonitoringRunStatus::AwaitingProtocol, $run->refresh()->status);
        $job->assertReleased(15);
    }

    public function test_handle_releases_with_backoff_when_transient(): void
    {
        $this->writeFixture('CONSDECLARACAO13', [
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 503,
            'body' => [],
        ]);
        $run = $this->claim($this->createAccount(), 'CONSDECLARACAO13', 'transient-release-key');

        $job = (new ExecuteSerproJob($run->id))->withFakeQueueInteractions();
        $job->handle(app(SerproExecutor::class));

        $this->assertSame(MonitoringRunStatus::Transient, $run->refresh()->status);
        $job->assertReleased(15);
    }

    public function test_release_backoff_grows_with_the_queue_attempt_number(): void
    {
        $this->writeFixture('CONSDECLARACAO13', [
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 503,
            'body' => [],
        ]);
        $run = $this->claim($this->createAccount(), 'CONSDECLARACAO13', 'transient-attempt-key');

        $job = (new ExecuteSerproJob($run->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle(app(SerproExecutor::class));

        $job->assertReleased(60);
    }

    public function test_handle_releases_by_retry_after_when_rate_limited(): void
    {
        $this->writeFixture('CONSDECLARACAO13', [
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 429,
            'body' => ['retry_after' => 45],
        ]);
        $run = $this->claim($this->createAccount(), 'CONSDECLARACAO13', 'limited-release-key');

        $job = (new ExecuteSerproJob($run->id))->withFakeQueueInteractions();
        $job->handle(app(SerproExecutor::class));

        $this->assertSame(MonitoringRunStatus::Limited, $run->refresh()->status);
        $job->assertReleased(45);
    }

    public function test_failed_marks_a_pending_run_as_queue_attempts_exhausted(): void
    {
        $run = MonitoringRun::factory()->create();

        (new ExecuteSerproJob($run->id))->failed(new RuntimeException('queue exhausted'));

        $run->refresh();

        $this->assertSame(MonitoringRunStatus::Failed, $run->status);
        $this->assertSame('queue_attempts_exhausted', $run->error_code);
        $this->assertNotNull($run->finished_at);
    }

    public function test_failed_marks_a_retryable_run_without_throwing(): void
    {
        $run = MonitoringRun::factory()->create([
            'status' => MonitoringRunStatus::Transient,
            'error_code' => 'transient_error',
            'started_at' => now(),
        ]);

        (new ExecuteSerproJob($run->id))->failed(new RuntimeException('queue exhausted'));

        $run->refresh();

        $this->assertSame(MonitoringRunStatus::Failed, $run->status);
        $this->assertSame('queue_attempts_exhausted', $run->error_code);
    }

    public function test_failed_leaves_terminal_runs_untouched(): void
    {
        $run = MonitoringRun::factory()->completed()->create();

        (new ExecuteSerproJob($run->id))->failed(new RuntimeException('queue exhausted'));

        $run->refresh();

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);
        $this->assertNull($run->error_code);
    }

    public function test_serpro_queue_connection_and_monitoring_queue_defaults_are_documented(): void
    {
        $this->assertSame('serpro', config('monitoring.queue'));
        $this->assertSame('serpro', config('monitoring.queue_connection'));
        $this->assertSame(8, config('monitoring.limits.max_attempts'));

        $connection = config('queue.connections.serpro');

        $this->assertIsArray($connection);
        $this->assertSame('database', $connection['driver']);
        $this->assertSame('jobs', $connection['table']);
        $this->assertSame('serpro', $connection['queue']);
        $this->assertSame(300, $connection['retry_after']);
        $this->assertTrue($connection['after_commit']);
    }

    private function claim(Account $account, string $operation, string $key): MonitoringRun
    {
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $definition = MonitoringDefinition::factory()->create([
            'operations' => [$operation],
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
        ]);

        $enrollment = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
        ]);

        return app(SerproExecutor::class)->claim($enrollment, $key);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function writeFixture(string $operation, array $fixture): void
    {
        $directory = storage_path('framework/testing/job-fixtures-'.uniqid());
        File::ensureDirectoryExists($directory);
        File::put(
            $directory.'/'.$operation.'.json',
            (string) json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->tempDirectories[] = $directory;
        config()->set('monitoring.fixtures_path', $directory);
    }
}
