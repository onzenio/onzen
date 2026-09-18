<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorStatus;
use App\Enums\MonitoringRunStatus;
use App\Exceptions\SerproBlockedException;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\Plan;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproSettings;
use App\Services\Monitoring\QueryQuotaService;
use App\Services\Monitoring\SerproExecutor;
use App\Services\Monitoring\SerproRecovery;
use App\Support\CurrentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeResultProjector;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class QueryQuotaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $tempDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->app->instance(ResultProjector::class, new FakeResultProjector);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        $this->travelBack();

        parent::tearDown();
    }

    public function test_first_reservation_consumes_one_unit_and_usage_reports_it(): void
    {
        $account = $this->accountWithVolume(3);
        $run = $this->makeRun($account);

        $this->quota()->reserve($account, $run);

        $this->assertDatabaseHas('query_quota_consumptions', [
            'account_id' => $account->id,
            'run_id' => $run->id,
            'trigger' => MonitoringRun::TRIGGER_MANUAL,
            'period' => $this->period(),
        ]);

        $this->assertSame([
            'period' => $this->period(),
            'consumed' => 1,
            'limit' => 3,
        ], $this->quota()->usage($account));
    }

    public function test_replaying_the_same_run_is_not_charged_twice(): void
    {
        $account = $this->accountWithVolume(3);
        $run = $this->makeRun($account);
        $quota = $this->quota();

        $quota->reserve($account, $run);
        $quota->reserve($account, $run);
        $quota->reserve($account, $run);

        $this->assertDatabaseCount('query_quota_consumptions', 1);
        $this->assertSame(1, $quota->usage($account)['consumed']);
    }

    public function test_a_preexisting_consumption_is_an_idempotent_success_not_a_500(): void
    {
        $account = $this->accountWithVolume(1);
        $run = $this->makeRun($account);

        // The winner of a concurrent reservation is already committed when
        // this caller reaches the insert: the unique `run_id` must resolve to
        // success (replay), never to a surfaced database error.
        DB::table('query_quota_consumptions')->insert([
            'account_id' => $account->id,
            'run_id' => $run->id,
            'trigger' => MonitoringRun::TRIGGER_MANUAL,
            'period' => $this->period(),
            'created_at' => now(),
        ]);

        $this->quota()->reserve($account, $run);

        $this->assertDatabaseCount('query_quota_consumptions', 1);
        $this->assertSame(1, $this->quota()->usage($account)['consumed']);
    }

    public function test_reservation_refuses_a_run_from_another_account(): void
    {
        $owner = $this->accountWithVolume(2);
        $other = $this->accountWithVolume(2);
        $run = $this->makeRun($owner);
        $quota = $this->quota();

        try {
            $quota->reserve($other, $run);
            $this->fail('A run may only be charged to its own Account.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('quota_account_mismatch', $exception->getMessage());
        }

        $this->assertDatabaseCount('query_quota_consumptions', 0);
        $this->assertSame(0, $quota->usage($owner)['consumed']);
        $this->assertSame(0, $quota->usage($other)['consumed']);
    }

    public function test_deleting_a_run_with_a_consumption_is_refused_and_the_consumption_remains(): void
    {
        $account = $this->accountWithVolume(2);
        $run = $this->makeRun($account);

        $this->quota()->reserve($account, $run);

        try {
            $run->delete();
            $this->fail('A run with a consumption must not be deleted.');
        } catch (QueryException) {
            // The FK restrict protects the immutable consumption.
        }

        $this->assertDatabaseHas('monitoring_runs', ['id' => $run->id]);
        $this->assertDatabaseHas('query_quota_consumptions', ['run_id' => $run->id]);
        $this->assertSame(1, $this->quota()->usage($account)['consumed']);
    }

    public function test_exhausted_volume_blocks_with_422_upgrade_message_and_no_consumption(): void
    {
        $account = $this->accountWithVolume(1);
        $first = $this->makeRun($account);
        $second = $this->makeRun($account);
        $quota = $this->quota();

        $quota->reserve($account, $first);

        try {
            $quota->reserve($account, $second);
            $this->fail('An exhausted Plan must refuse the reservation.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString('upgrade', mb_strtolower($exception->getMessage()));
        }

        $this->assertDatabaseMissing('query_quota_consumptions', ['run_id' => $second->id]);
        $this->assertSame(1, $quota->usage($account)['consumed']);
    }

    public function test_sequential_reservations_at_the_ceiling_never_exceed_the_limit(): void
    {
        $account = $this->accountWithVolume(2);
        $quota = $this->quota();

        $quota->reserve($account, $this->makeRun($account));
        $quota->reserve($account, $this->makeRun($account));

        try {
            $quota->reserve($account, $this->makeRun($account));
            $this->fail('The Plan ceiling must not be exceeded.');
        } catch (ValidationException) {
            // Expected: the third reservation overflows.
        }

        $this->assertDatabaseCount('query_quota_consumptions', 2);
        $this->assertSame(2, $quota->usage($account)['consumed']);
    }

    public function test_automatic_trigger_consumes_the_plan_volume(): void
    {
        $account = $this->accountWithVolume(2);
        $run = $this->makeRun($account, MonitoringRun::TRIGGER_AUTOMATIC);

        $this->quota()->reserve($account, $run);

        $this->assertDatabaseHas('query_quota_consumptions', [
            'account_id' => $account->id,
            'run_id' => $run->id,
            'trigger' => MonitoringRun::TRIGGER_AUTOMATIC,
            'period' => $this->period(),
        ]);
        $this->assertSame(1, $this->quota()->usage($account)['consumed']);
    }

    public function test_consumption_never_leaks_across_accounts(): void
    {
        $one = $this->accountWithVolume(1);
        $two = $this->accountWithVolume(1);
        $quota = $this->quota();

        $quota->reserve($one, $this->makeRun($one));

        CurrentAccount::set($one->id);

        try {
            $this->assertSame(0, $quota->usage($two)['consumed']);
            $quota->reserve($two, $this->makeRun($two));
            $this->assertSame(1, $quota->usage($two)['consumed']);
            $this->assertSame(1, $quota->usage($one)['consumed']);
        } finally {
            CurrentAccount::clear();
        }

        $this->assertDatabaseCount('query_quota_consumptions', 2);
    }

    public function test_account_without_a_plan_is_blocked_fail_closed(): void
    {
        $account = $this->createAccount(['plan_id' => null]);
        $run = $this->makeRun($account);

        try {
            $this->quota()->reserve($account, $run);
            $this->fail('An Account without a Plan must not consume volume.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString('upgrade', mb_strtolower($exception->getMessage()));
        }

        $this->assertDatabaseMissing('query_quota_consumptions', ['run_id' => $run->id]);
    }

    public function test_executor_blocks_the_run_before_any_transport_when_quota_is_exhausted(): void
    {
        $account = $this->accountWithVolume(1);
        $transport = $this->openTransport($account);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $quota = $this->quota();

        // Burn the only unit of the cycle with another run.
        $quota->reserve($account, $this->makeRun($account));

        $executor = app(SerproExecutor::class);
        $run = $executor->execute($executor->claim($enrollment, 'blocked-key'));

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame(QueryQuotaService::ERROR_EXCEEDED, $run->error_code);
        $this->assertCount(0, $transport->tokenCalls, 'Quota must block before any credential/token work.');
        $this->assertCount(0, $transport->callCalls, 'Quota must block before any transport call.');
        $this->assertDatabaseMissing('query_quota_consumptions', ['run_id' => $run->id]);
        $this->assertSame(1, $quota->usage($account)['consumed']);
    }

    public function test_executor_job_records_a_factual_blocked_run_without_throwing(): void
    {
        $account = $this->accountWithVolume(0);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $run = app(SerproExecutor::class)->claim($enrollment, 'job-blocked-key');

        $job = (new ExecuteSerproJob($run->id))->withFakeQueueInteractions();
        $job->handle(app(SerproExecutor::class), app(SerproRecovery::class));

        $run->refresh();

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame(QueryQuotaService::ERROR_EXCEEDED, $run->error_code);
        $this->assertNotNull($run->finished_at);
        $job->assertNotReleased();
        $this->assertDatabaseCount('query_quota_consumptions', 0);

        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'attempt' => 1,
            'status' => 'blocked',
            'classification' => 'quota_exceeded',
        ]);
    }

    public function test_executor_retry_replays_the_reservation_without_charging_again(): void
    {
        $account = $this->accountWithVolume(5);
        $this->writeFixture('CONSDECLARACAO13', [
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 503,
            'body' => [],
        ]);
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $executor = app(SerproExecutor::class);
        $quota = $this->quota();

        $run = $executor->execute($executor->claim($enrollment, 'retry-quota-key'));

        $this->assertSame(MonitoringRunStatus::Transient, $run->status);
        $this->assertSame(1, $quota->usage($account)['consumed']);

        // The retry walks through `execute` again (and thus through the quota
        // gate) after the backoff, now with a successful fixture.
        $this->writeFixture('CONSDECLARACAO13', [
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 200,
            'body' => ['dados' => ['situacao' => 'ativa']],
        ]);

        $this->travel(16)->seconds();

        $run = $executor->execute($run);

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);
        $this->assertSame(1, $quota->usage($account)['consumed']);
        $this->assertDatabaseCount('query_quota_consumptions', 1);
    }

    private function quota(): QueryQuotaService
    {
        return app(QueryQuotaService::class);
    }

    private function accountWithVolume(int $volume): Account
    {
        $plan = Plan::factory()->create(['monthly_query_volume' => $volume]);

        return $this->createAccount(['plan_id' => $plan->id]);
    }

    private function makeRun(Account $account, string $trigger = MonitoringRun::TRIGGER_MANUAL): MonitoringRun
    {
        return MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'trigger' => $trigger,
        ]);
    }

    private function enrollment(Account $account, string $operation): MonitoringEnrollment
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
        ]);
    }

    private function openTransport(Account $account): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $ref = 'secret:quota-'.uniqid();
        app(VaultResolver::class)->put($ref, (string) json_encode([
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => '65.396.736/0001-76',
        ]));

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => $ref,
        ]);

        SerproRequestAuthor::factory()->create([
            'account_id' => $account->id,
            'status' => AuthorStatus::Active,
            'certificate_expires_at' => now()->addYear(),
        ]);

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        return $transport;
    }

    private function period(): string
    {
        return Carbon::now('America/Sao_Paulo')->format('Y-m');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function writeFixture(string $operation, array $fixture): void
    {
        $directory = storage_path('framework/testing/quota-fixtures-'.uniqid());
        File::ensureDirectoryExists($directory);
        File::put(
            $directory.'/'.$operation.'.json',
            (string) json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->tempDirectories[] = $directory;
        config()->set('monitoring.fixtures_path', $directory);
    }
}
