<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Contracts\SerproEvents;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorStatus;
use App\Enums\MonitoringRunStatus;
use App\Events\Monitoring\SerproActionFinished;
use App\Events\Monitoring\SerproRunFinished;
use App\Events\Monitoring\SerproRunStarted;
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
use App\Services\Monitoring\MonitoringScheduler;
use App\Services\Monitoring\QueryQuotaService;
use App\Services\Monitoring\SerproEventEmitter;
use App\Services\Monitoring\SerproExecutor;
use App\Support\CurrentAccount;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\FakeResultProjector;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class SerproOperationalEventsTest extends TestCase
{
    use RefreshDatabase;

    private FakeResultProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projector = new FakeResultProjector;
        $this->app->instance(ResultProjector::class, $this->projector);
    }

    public function test_run_started_payload_carries_only_opaque_identifiers(): void
    {
        Event::fake([SerproRunStarted::class, SerproRunFinished::class]);

        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'events-started-key'),
        );

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);

        Event::assertDispatched(SerproRunStarted::class, function (SerproRunStarted $event) use ($run, $account, $enrollment): bool {
            $context = $event->context;

            $this->assertSame([
                'run_id', 'account_id', 'client_id', 'enrollment_id', 'definition_key',
                'operation_code', 'trigger', 'environment', 'dry_run', 'status',
                'attempt', 'started_at',
            ], array_keys($context));

            $this->assertSame((string) $run->id, $context['run_id']);
            $this->assertSame((string) $account->id, $context['account_id']);
            $this->assertSame((string) $enrollment->client_id, $context['client_id']);
            $this->assertSame((string) $enrollment->id, $context['enrollment_id']);
            $this->assertSame((string) $run->definition_key, $context['definition_key']);
            $this->assertSame('CONSDECLARACAO13', $context['operation_code']);
            $this->assertSame('manual', $context['trigger']);
            $this->assertSame('homologacao', $context['environment']);
            $this->assertTrue($context['dry_run']);
            $this->assertSame('running', $context['status']);
            $this->assertSame(1, $context['attempt']);
            $this->assertNotNull($context['started_at']);

            return true;
        });
    }

    public function test_run_finished_payload_carries_the_outcome_without_secret_or_fiscal_content(): void
    {
        Event::fake([SerproRunStarted::class, SerproRunFinished::class]);

        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'events-finished-key'),
        );

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);

        Event::assertDispatched(SerproRunFinished::class, function (SerproRunFinished $event) use ($run, $account, $enrollment): bool {
            $context = $event->context;

            $this->assertSame([
                'run_id', 'account_id', 'client_id', 'enrollment_id', 'definition_key',
                'operation_code', 'trigger', 'environment', 'dry_run', 'status',
                'attempt', 'error_code', 'reason', 'retryable', 'terminal',
                'started_at', 'finished_at', 'duration_ms',
            ], array_keys($context));

            $this->assertSame((string) $run->id, $context['run_id']);
            $this->assertSame((string) $account->id, $context['account_id']);
            $this->assertSame((string) $enrollment->client_id, $context['client_id']);
            $this->assertSame((string) $enrollment->id, $context['enrollment_id']);
            $this->assertSame((string) $run->definition_key, $context['definition_key']);
            $this->assertSame('CONSDECLARACAO13', $context['operation_code']);
            $this->assertSame('manual', $context['trigger']);
            $this->assertSame('homologacao', $context['environment']);
            $this->assertTrue($context['dry_run']);
            $this->assertSame('completed', $context['status']);
            $this->assertSame(1, $context['attempt']);
            $this->assertNull($context['error_code']);
            $this->assertNull($context['reason']);
            $this->assertFalse($context['retryable']);
            $this->assertTrue($context['terminal']);
            $this->assertNotNull($context['started_at']);
            $this->assertNotNull($context['finished_at']);
            $this->assertIsInt($context['duration_ms']);

            $encoded = (string) json_encode($context);

            $this->assertStringNotContainsString('00000000000000000013', $encoded, 'Fiscal content must never be emitted.');
            $this->assertStringNotContainsString('declaracoes', $encoded, 'The emitted payload must only carry opaque identifiers.');
            $this->assertStringNotContainsString('pedidoDados', $encoded);

            return true;
        });
    }

    public function test_sensitive_exception_message_is_redacted_before_emission(): void
    {
        Event::fake([SerproRunStarted::class, SerproRunFinished::class]);

        $account = $this->createAccount();
        $transport = $this->openTransport($account);
        $transport->onCall = fn (): never => throw new RuntimeException(
            'SERPRO call failed token=CANARY-TOKEN password=s3cr3t-password pfx=UEZYYnl0ZXM='
            .' Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.CANARY-PAYLOAD.CANARY-SIGNATURE',
        );
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'events-redaction-key'),
        );

        $this->assertSame(MonitoringRunStatus::Transient, $run->status);
        $this->assertSame('transport_error', $run->error_code);

        Event::assertDispatched(SerproRunFinished::class, function (SerproRunFinished $event): bool {
            $context = $event->context;

            $this->assertSame('transport_error', $context['error_code']);
            $this->assertNotNull($context['reason']);
            $this->assertStringContainsString('[redacted]', $context['reason']);
            $this->assertTrue($context['retryable']);
            $this->assertFalse($context['terminal']);
            $this->assertNull($context['finished_at']);

            $encoded = (string) json_encode($context);

            foreach ([
                'CANARY-TOKEN',
                's3cr3t-password',
                'UEZYYnl0ZXM=',
                'CANARY-PAYLOAD',
                'CANARY-SIGNATURE',
            ] as $canary) {
                $this->assertStringNotContainsString($canary, $encoded, "Secret {$canary} leaked into the event payload.");
            }

            return true;
        });
    }

    public function test_emitter_failure_never_breaks_the_execution(): void
    {
        Log::spy();

        $this->app->instance(SerproEvents::class, $this->throwingEvents());

        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        $run = $this->executor()->execute(
            $this->executor()->claim($enrollment, 'events-failure-key'),
        );

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);
        $this->assertSame(1, $this->projector->calls());
        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'attempt' => 1,
            'status' => 'completed',
            'classification' => 'ok',
        ]);

        Log::shouldHaveReceived('error')
            ->with('serpro_event_emission_failed', Mockery::type('array'))
            ->atLeast()->once();
    }

    public function test_queue_exhaustion_emits_finished_with_the_exhausted_outcome(): void
    {
        Event::fake([SerproRunStarted::class, SerproRunFinished::class]);

        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $run = $this->executor()->claim($enrollment, 'events-exhausted-key');

        (new ExecuteSerproJob($run->id))->failed(new RuntimeException('queue exhausted'));

        $run->refresh();

        $this->assertSame(MonitoringRunStatus::Failed, $run->status);
        $this->assertSame(ExecuteSerproJob::ERROR_EXHAUSTED, $run->error_code);

        Event::assertNotDispatched(SerproRunStarted::class);
        Event::assertDispatched(SerproRunFinished::class, function (SerproRunFinished $event) use ($run, $account, $enrollment): bool {
            $context = $event->context;

            $this->assertSame((string) $run->id, $context['run_id']);
            $this->assertSame((string) $account->id, $context['account_id']);
            $this->assertSame((string) $enrollment->client_id, $context['client_id']);
            $this->assertSame('failed', $context['status']);
            $this->assertSame(ExecuteSerproJob::ERROR_EXHAUSTED, $context['error_code']);
            $this->assertTrue($context['terminal']);
            $this->assertFalse($context['retryable']);

            return true;
        });
    }

    public function test_queue_exhaustion_event_failure_does_not_break_the_job(): void
    {
        Log::spy();

        $this->app->instance(SerproEvents::class, $this->throwingEvents());

        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $run = $this->executor()->claim($enrollment, 'events-exhausted-failure-key');

        (new ExecuteSerproJob($run->id))->failed(new RuntimeException('queue exhausted'));

        $this->assertSame(MonitoringRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(ExecuteSerproJob::ERROR_EXHAUSTED, $run->error_code);

        Log::shouldHaveReceived('error')
            ->with('serpro_event_emission_failed', Mockery::type('array'))
            ->atLeast()->once();
    }

    public function test_scheduler_quota_block_emits_finished_with_the_blocked_outcome(): void
    {
        Event::fake([SerproRunStarted::class, SerproRunFinished::class]);

        $account = $this->exhaustedAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        try {
            app(MonitoringScheduler::class)->schedule($enrollment);
            $this->fail('The scheduler should have refused the exhausted quota.');
        } catch (ValidationException) {
            // Expected: quota refusal rethrows the 422 upgrade message.
        }

        $run = MonitoringRun::query()->where('account_id', $account->id)->firstOrFail();

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame(QueryQuotaService::ERROR_EXCEEDED, $run->error_code);

        Event::assertNotDispatched(SerproRunStarted::class);
        Event::assertDispatched(SerproRunFinished::class, function (SerproRunFinished $event) use ($run, $account, $enrollment): bool {
            $context = $event->context;

            $this->assertSame((string) $run->id, $context['run_id']);
            $this->assertSame((string) $account->id, $context['account_id']);
            $this->assertSame((string) $enrollment->client_id, $context['client_id']);
            $this->assertSame('blocked', $context['status']);
            $this->assertSame(QueryQuotaService::ERROR_EXCEEDED, $context['error_code']);
            $this->assertTrue($context['terminal']);
            $this->assertFalse($context['retryable']);

            return true;
        });
    }

    public function test_scheduler_block_event_failure_does_not_break_the_scheduler(): void
    {
        Log::spy();

        $this->app->instance(SerproEvents::class, $this->throwingEvents());

        $account = $this->exhaustedAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');

        try {
            app(MonitoringScheduler::class)->schedule($enrollment);
            $this->fail('The scheduler should have refused the exhausted quota.');
        } catch (ValidationException) {
            // Expected: the emitter failure never masks the quota refusal.
        }

        $run = MonitoringRun::query()->where('account_id', $account->id)->firstOrFail();

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertDatabaseHas('monitoring_attempts', [
            'run_id' => $run->id,
            'attempt' => 1,
            'status' => 'blocked',
            'classification' => QueryQuotaService::ERROR_EXCEEDED,
        ]);

        Log::shouldHaveReceived('error')
            ->with('serpro_event_emission_failed', Mockery::type('array'))
            ->atLeast()->once();
    }

    public function test_error_code_is_redacted_in_the_finished_payload(): void
    {
        Event::fake([SerproRunFinished::class]);

        $account = $this->createAccount();
        $enrollment = $this->enrollment($account, 'CONSDECLARACAO13');
        $run = $this->executor()->claim($enrollment, 'events-code-redaction-key');
        $run->forceFill([
            'status' => MonitoringRunStatus::Failed,
            'error_code' => 'token=CANARY-ERROR-CODE',
            'finished_at' => now(),
        ])->save();

        app(SerproEventEmitter::class)->runFinished($run);

        Event::assertDispatched(SerproRunFinished::class, function (SerproRunFinished $event): bool {
            $this->assertSame('token=[redacted]', $event->context['error_code']);
            $this->assertStringNotContainsString('CANARY-ERROR-CODE', (string) json_encode($event->context));

            return true;
        });
    }

    public function test_action_error_code_is_redacted_too(): void
    {
        Event::fake([SerproActionFinished::class]);

        app(SerproEventEmitter::class)->actionFinished(
            requestId: '93',
            accountId: '7',
            operationCode: 'EMITIRDAS12',
            status: 'failed',
            errorCode: 'pfx=CANARY-ACTION-CODE',
        );

        Event::assertDispatched(SerproActionFinished::class, function (SerproActionFinished $event): bool {
            $this->assertSame('pfx=[redacted]', $event->context['error_code']);
            $this->assertStringNotContainsString('CANARY-ACTION-CODE', (string) json_encode($event->context));

            return true;
        });
    }

    public function test_action_finished_emitter_api_emits_a_redacted_structured_payload(): void
    {
        Event::fake([SerproActionFinished::class]);

        app(SerproEventEmitter::class)->actionFinished(
            requestId: '91',
            accountId: '7',
            clientId: '13',
            operationCode: 'EMITIRDAS12',
            status: 'failed',
            errorCode: 'serpro_rejected',
            reason: 'rejected: token=CANARY-ACTION-TOKEN',
            retryable: false,
            terminal: true,
            finishedAt: Carbon::parse('2026-09-13T12:00:00-03:00'),
        );

        Event::assertDispatched(SerproActionFinished::class, function (SerproActionFinished $event): bool {
            $context = $event->context;

            $this->assertSame([
                'request_id', 'account_id', 'client_id', 'operation_code', 'status',
                'error_code', 'reason', 'retryable', 'terminal', 'finished_at',
            ], array_keys($context));

            $this->assertSame('91', $context['request_id']);
            $this->assertSame('7', $context['account_id']);
            $this->assertSame('13', $context['client_id']);
            $this->assertSame('EMITIRDAS12', $context['operation_code']);
            $this->assertSame('failed', $context['status']);
            $this->assertSame('serpro_rejected', $context['error_code']);
            $this->assertSame('rejected: token=[redacted]', $context['reason']);
            $this->assertFalse($context['retryable']);
            $this->assertTrue($context['terminal']);
            $this->assertSame('2026-09-13T12:00:00-03:00', $context['finished_at']);

            $this->assertStringNotContainsString('CANARY-ACTION-TOKEN', (string) json_encode($context));

            return true;
        });
    }

    public function test_fiscal_xml_content_is_never_emitted(): void
    {
        Event::fake([SerproActionFinished::class]);

        app(SerproEventEmitter::class)->actionFinished(
            requestId: '92',
            accountId: '7',
            operationCode: 'CONSDECLARACAO13',
            status: 'failed',
            errorCode: 'invalid_response',
            reason: '<dados><declaracao>CANARY-XML-FISCAL</declaracao></dados>',
        );

        Event::assertDispatched(SerproActionFinished::class, function (SerproActionFinished $event): bool {
            $this->assertSame('[redacted]', $event->context['reason']);
            $this->assertStringNotContainsString('CANARY-XML-FISCAL', (string) json_encode($event->context));

            return true;
        });
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
        CurrentAccount::set($account->id);
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

    private function openTransport(Account $account): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $ref = 'secret:events-'.uniqid();
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

    private function exhaustedAccount(): Account
    {
        $plan = Plan::factory()->create(['monthly_query_volume' => 0]);

        return $this->createAccount(['plan_id' => $plan->id]);
    }

    private function throwingEvents(): SerproEvents
    {
        return new class implements SerproEvents
        {
            public function runStarted(MonitoringRun $run): void
            {
                throw new RuntimeException('event sink down');
            }

            public function runFinished(MonitoringRun $run, ?string $reason = null): void
            {
                throw new RuntimeException('event sink down');
            }

            public function actionFinished(
                string $requestId,
                string $accountId,
                string $operationCode,
                string $status,
                ?string $clientId = null,
                ?string $errorCode = null,
                ?string $reason = null,
                bool $retryable = false,
                bool $terminal = true,
                ?DateTimeInterface $finishedAt = null,
            ): void {
                throw new RuntimeException('event sink down');
            }
        };
    }
}
