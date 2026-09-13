<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorStatus;
use App\Enums\MonitoringRunStatus;
use App\Exceptions\SerproBlockedException;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproSettings;
use App\Services\Monitoring\SerproExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\Support\FakeResultProjector;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class SerproExecutionTest extends TestCase
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

    public function test_dry_run_with_a_fixture_completes_and_projects_the_result(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'dry-run-key'),
        );

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);
        $this->assertTrue($run->dry_run);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $this->assertSame(1, $projector->calls());
        $result = $projector->projections[0]['result'];
        $this->assertSame('fixture', $result['source']);
        $this->assertSame('CONSDECLARACAO13', $result['operation_code']);
        $this->assertSame(200, $result['http_status']);
        $this->assertIsArray($result['body']);

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'attempt' => 1,
            'status' => 'completed',
            'response_code' => 200,
            'classification' => 'ok',
        ]);
    }

    public function test_missing_fixture_blocks_without_inventing_a_result(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'PAGAMENTOS71');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'missing-fixture-key'),
        );

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('fixture_missing', $run->error_code);
        $this->assertSame(0, $projector->calls());

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'attempt' => 1,
            'status' => 'blocked',
            'classification' => 'fixture_missing',
        ]);
    }

    public function test_claim_is_idempotent_and_never_calls_the_transport(): void
    {
        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $executor = $this->executor();

        $first = $executor->claim($enrollment, 'once-only');
        $second = $executor->claim($enrollment, 'once-only');

        $this->assertTrue($first->is($second));
        $this->assertSame(MonitoringRunStatus::Pending, $second->status);
        $this->assertSame(1, MonitoringRun::query()->count());
        $this->assertCount(0, $transport->callCalls);
        $this->assertCount(0, $transport->tokenCalls);
    }

    public function test_repeating_the_idempotency_key_does_not_resend_traffic(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $executor = $this->executor();

        $first = $executor->execute($executor->claim($enrollment, 'repeat-key'));
        $second = $executor->execute($executor->claim($enrollment, 'repeat-key'));

        $this->assertTrue($first->is($second));
        $this->assertSame(MonitoringRunStatus::Completed, $second->status);
        $this->assertSame(1, MonitoringRun::query()->count());
        $this->assertCount(1, $transport->callCalls);
        $this->assertSame(1, $projector->calls());
        $this->assertDatabaseCount('monitoring_attempts', 1);
    }

    public function test_claim_refuses_reusing_a_key_for_another_enrollment(): void
    {
        $account = $this->createAccount();
        $one = $this->enrollment($account, 'CONSDECLARACAO13');
        $two = $this->enrollment($account, 'CONSDECLARACAO142');
        $executor = $this->executor();

        $executor->claim($one, 'shared-key');

        $this->expectExceptionMessage('idempotency_key_conflict');

        $executor->claim($two, 'shared-key');
    }

    public function test_claim_refuses_an_ended_enrollment_without_creating_a_run(): void
    {
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13', [
            'status' => MonitoringEnrollment::STATUS_ENDED,
        ]);

        try {
            $this->executor()->claim($enrollment, 'ended-key');
            $this->fail('An ended enrollment must not be claimed.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('enrollment_inactive', $exception->getMessage());
        }

        $this->assertDatabaseCount('monitoring_runs', 0);
    }

    public function test_superseded_run_is_discarded_without_projection(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $executor = $this->executor();

        $run = $executor->claim($enrollment, 'fenced-before');
        $enrollment->pause('outorga revogada');

        $executed = $executor->execute($run);

        $this->assertSame(MonitoringRunStatus::Discarded, $executed->status);
        $this->assertSame('superseded', $executed->error_code);
        $this->assertNotNull($executed->finished_at);
        $this->assertSame(0, $projector->calls());

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'status' => 'discarded',
            'classification' => 'superseded',
        ]);
    }

    public function test_result_is_discarded_when_the_enrollment_version_advances_during_the_call(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $executor = $this->executor();

        $transport->onCall = function () use ($enrollment): array {
            $enrollment->pause('outorga republicada');

            return ['status' => 200, 'body' => ['dados' => ['situacao' => 'ativa']]];
        };

        $run = $executor->execute($executor->claim($enrollment, 'fenced-during'));

        $this->assertSame(MonitoringRunStatus::Discarded, $run->status);
        $this->assertSame('superseded', $run->error_code);
        $this->assertCount(1, $transport->callCalls);
        $this->assertSame(0, $projector->calls());
    }

    public function test_result_is_discarded_when_the_enrollment_version_advances_while_projecting(): void
    {
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $projector = new class($enrollment) implements ResultProjector
        {
            public int $calls = 0;

            public function __construct(private readonly MonitoringEnrollment $enrollment) {}

            public function project(MonitoringRun $run, array $result): void
            {
                $this->calls++;

                // A projection write inside the completion transaction that
                // must be rolled back when the fencing token is superseded.
                $run->forceFill(['external_code' => 'projected'])->save();

                // A version bump landing while the result is projected.
                $this->enrollment->pause('outorga republicada');
            }
        };
        $this->app->instance(ResultProjector::class, $projector);

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'fenced-while-projecting'));

        $this->assertSame(1, $projector->calls);
        $this->assertSame(MonitoringRunStatus::Discarded, $run->status);
        $this->assertSame('superseded', $run->error_code);
        $this->assertNotSame('projected', $run->external_code, 'The projection write must be rolled back.');
        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'status' => 'discarded',
            'classification' => 'superseded',
        ]);
    }

    public function test_projection_failure_ends_the_run_as_failed_and_records_the_attempt(): void
    {
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $this->app->instance(ResultProjector::class, new class implements ResultProjector
        {
            public function project(MonitoringRun $run, array $result): void
            {
                throw new RuntimeException('projection exploded');
            }
        });

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'projection-failure-key'));

        $this->assertSame(MonitoringRunStatus::Failed, $run->status);
        $this->assertSame('projection_failed', $run->error_code);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $this->projector->calls());

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'status' => 'failed',
            'classification' => 'projection_failed',
        ]);
    }

    public function test_protocol_response_waits_with_protocol_persisted(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'SOLICITARPROTOCOLO91');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'protocol-key'),
        );

        $this->assertSame(MonitoringRunStatus::AwaitingProtocol, $run->status);
        $this->assertSame('SITFIS-PROTO-91', $run->protocol);
        $this->assertNull($run->finished_at);
        $this->assertSame(0, $projector->calls());

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'status' => 'awaiting_protocol',
            'response_code' => 202,
            'classification' => 'protocol_pending',
        ]);
    }

    public function test_protocol_eta_from_the_fixture_is_persisted(): void
    {
        $account = $this->createAccount();
        $eta = now()->addMinutes(15);
        $this->writeFixture('CONSDECLARACAO13', [
            'source' => 'test',
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 202,
            'body' => [
                'protocol' => ['protocol_id' => 'PROTO-99', 'obtained' => false],
                'eta' => $eta->toIso8601String(),
            ],
        ]);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'eta-key'),
        );

        $this->assertSame(MonitoringRunStatus::AwaitingProtocol, $run->status);
        $this->assertSame('PROTO-99', $run->protocol);
        $this->assertNotNull($run->eta);
        $this->assertSame($eta->format('Y-m-d H:i:s'), $run->eta->format('Y-m-d H:i:s'));
    }

    public function test_transport_failure_is_recorded_as_transient_without_projection(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $transport->callResponses = [['status' => 503, 'body' => []]];
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'transient-key'),
        );

        $this->assertSame(MonitoringRunStatus::Transient, $run->status);
        $this->assertSame('transient_error', $run->error_code);
        $this->assertNull($run->finished_at);
        $this->assertSame(0, $projector->calls());

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'status' => 'transient',
            'response_code' => 503,
            'classification' => 'transient_error',
        ]);
    }

    public function test_rate_limit_is_recorded_as_limited_with_retry_after(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $transport->callResponses = [[
            'status' => 429,
            'headers' => ['Retry-After' => ['30']],
            'body' => [],
        ]];
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'limited-key'),
        );

        $this->assertSame(MonitoringRunStatus::Limited, $run->status);
        $this->assertSame('rate_limited', $run->error_code);
        $this->assertSame(0, $projector->calls());

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'status' => 'limited',
            'response_code' => 429,
            'classification' => 'rate_limited',
            'retry_after' => 30,
        ]);
    }

    public function test_transport_rejection_ends_as_rejected_without_projection(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $transport->callResponses = [['status' => 400, 'body' => ['code' => 'business_rejection']]];
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'rejected-key'),
        );

        $this->assertSame(MonitoringRunStatus::Rejected, $run->status);
        $this->assertSame('definitive_rejection', $run->error_code);
        $this->assertSame(0, $projector->calls());
    }

    public function test_missing_credential_blocks_before_any_traffic(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        SerproSettings::current()->update(['transport_approved' => true, 'transport_approved_at' => now()]);
        SerproRequestAuthor::factory()->create([
            'account_id' => $account->id,
            'status' => AuthorStatus::Active,
            'certificate_expires_at' => now()->addYear(),
        ]);
        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'no-credential-key'),
        );

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('serpro_credential_missing', $run->error_code);
        $this->assertCount(0, $transport->tokenCalls);
        $this->assertCount(0, $transport->callCalls);
        $this->assertSame(0, $projector->calls());
    }

    public function test_missing_author_blocks_before_any_traffic(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account, withAuthor: false);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'no-author-key'),
        );

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('author_pending', $run->error_code);
        $this->assertCount(0, $transport->tokenCalls);
        $this->assertCount(0, $transport->callCalls);
        $this->assertSame(0, $projector->calls());
    }

    public function test_ineligible_author_blocks_before_any_traffic(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account, author: [
            'certificate_expires_at' => now()->subDay(),
        ]);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'ineligible-author-key'),
        );

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('author_ineligible', $run->error_code);
        $this->assertCount(0, $transport->tokenCalls);
        $this->assertCount(0, $transport->callCalls);
        $this->assertSame(0, $projector->calls());
    }

    public function test_terminal_run_execution_is_idempotent(): void
    {
        $projector = $this->projector;
        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $executor = $this->executor();

        $run = $executor->execute($executor->claim($enrollment, 'terminal-key'));
        $again = $executor->execute($run);

        $this->assertTrue($run->is($again));
        $this->assertCount(1, $transport->callCalls);
        $this->assertSame(1, $projector->calls());
    }

    public function test_claim_captures_operation_environment_dry_run_and_fencing_token(): void
    {
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13', ['version' => 4]);

        $run = $this->executor()->claim($enrollment, 'captured-key');

        $this->assertSame('CONSDECLARACAO13', $run->operation_code);
        $this->assertSame('CONSDECLARACAO13', $run->external_code);
        $this->assertSame($enrollment->definition_id, $run->definition_key);
        $this->assertSame('homologacao', $run->environment);
        $this->assertTrue($run->dry_run);
        $this->assertSame(4, $run->fencing_token);
        $this->assertSame('manual', $run->trigger);
    }

    private function executor(): SerproExecutor
    {
        return app(SerproExecutor::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrollment(Account $account, string $operation, array $attributes = []): MonitoringEnrollment
    {
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $definition = MonitoringDefinition::factory()->create([
            'operations' => [$operation],
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
        ]);

        return MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
            ...$attributes,
        ]);
    }

    private function openTransport(Account $account, bool $withAuthor = true, array $author = []): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $ref = 'secret:executor-'.uniqid();
        app(VaultResolver::class)->put($ref, (string) json_encode([
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => '65.396.736/0001-76',
        ]));

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => $ref,
        ]);

        if ($withAuthor) {
            SerproRequestAuthor::factory()->create([
                'account_id' => $account->id,
                'status' => AuthorStatus::Active,
                'certificate_expires_at' => now()->addYear(),
                ...$author,
            ]);
        }

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        return $transport;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function writeFixture(string $operation, array $fixture): void
    {
        $directory = storage_path('framework/testing/run-fixtures-'.uniqid());
        File::ensureDirectoryExists($directory);
        File::put(
            $directory.'/'.$operation.'.json',
            (string) json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->tempDirectories[] = $directory;
        config()->set('monitoring.fixtures_path', $directory);
    }
}
