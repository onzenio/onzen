<?php

namespace Tests\Feature;

use App\Contracts\ArtifactStore;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorStatus;
use App\Enums\SerproActionStatus;
use App\Events\Monitoring\SerproActionFinished;
use App\Exceptions\ArtifactStorageUnavailableException;
use App\Exceptions\SerproBlockedException;
use App\Jobs\ExecuteSerproActionJob;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MonitoringArtifact;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\ParcelmentInstallment;
use App\Models\ParcelmentOrder;
use App\Models\PowerOfAttorney;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproServiceRequest;
use App\Models\SerproSettings;
use App\Services\Monitoring\SerproActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class SerproActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('serpro_service_requests', [
            'id', 'account_id', 'client_id', 'enrollment_id', 'installment_id', 'operation_code',
            'modality', 'idempotency_key', 'status', 'protocol', 'document_ref', 'parameters',
            'metadata', 'requested_by_user_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_request_requires_explicit_confirmation_and_never_creates_an_action(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $transport = $this->openTransport($account, []);

        $this->assertRefused('explicit_confirmation_required', fn () => $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-confirmation-key',
            false,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
    }

    public function test_request_rejects_a_declaration_without_any_call(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $transport = $this->openTransport($account, []);

        $this->assertRefused('fiscal_action_out_of_scope', fn () => $this->executor()->request(
            $client,
            'TRANSDECLARACAO11',
            'action-declaration-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
    }

    public function test_request_refuses_a_gated_transport_without_creating_or_calling(): void
    {
        [, $client, $enrollment] = $this->context();
        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $this->assertRefused('serpro_gated', fn () => $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-gated-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
    }

    public function test_request_refuses_a_missing_procurement_without_creating_or_calling(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $transport = $this->openTransport($account, []);

        $this->assertRefused('outorga_pendente', fn () => $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-procurement-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
    }

    public function test_request_refuses_an_inactive_enrollment(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $transport = $this->openTransport($account, []);
        $enrollment->pause('manual');

        $this->assertRefused('enrollment_inactive', fn () => $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-inactive-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment->refresh(),
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
    }

    public function test_request_refuses_a_missing_credential_before_any_call(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $enrollment = $this->pgdasEnrollment($account, $client);
        $this->grantProcuration($client, '00146');
        SerproSettings::current()->update(['transport_approved' => true, 'transport_approved_at' => now()]);
        SerproRequestAuthor::factory()->create([
            'account_id' => $account->id,
            'status' => AuthorStatus::Active,
            'certificate_expires_at' => now()->addYear(),
        ]);
        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $this->assertRefused('serpro_credential_missing', fn () => $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-credential-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
    }

    public function test_request_refuses_a_missing_author_before_any_call(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $transport = $this->openTransport($account, [], withAuthor: false);

        $this->assertRefused('author_pending', fn () => $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-author-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
    }

    public function test_repeating_the_key_returns_the_existing_action_without_a_second_action_or_call(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $transport = $this->openTransport($account, []);

        $first = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-repeat-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $second = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-repeat-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
        Queue::assertPushed(ExecuteSerproActionJob::class, 1);
    }

    public function test_reusing_a_key_for_another_operation_conflicts(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, []);

        $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-conflict-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $other = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);

        $this->assertRefused('idempotency_key_conflict', fn () => $this->executor()->request(
            $other,
            'GERARDAS12',
            'action-conflict-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        ));

        $this->assertSame(1, SerproServiceRequest::query()->count());
    }

    public function test_confirmed_emission_stores_the_pgdas_pdf_as_an_artifact(): void
    {
        Queue::fake();
        Storage::fake('local');
        Event::fake([SerproActionFinished::class]);

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');

        $pdf = '%PDF-1.4 pgdas-das-canary';
        $transport = $this->openTransport($account, [[
            'status' => 200,
            'body' => ['periodoApuracao' => '202601', 'pdf' => base64_encode($pdf)],
        ]]);

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-pgdas-key',
            true,
            ['periodo_apuracao' => '202601', 'data_consolidacao' => '2026-02-10'],
            $enrollment,
        );

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Succeeded, $executed->status);
        $this->assertNotNull($executed->document_ref);
        $this->assertSame($pdf, app(ArtifactStore::class)->get((string) $executed->document_ref));

        $artifact = MonitoringArtifact::query()->where('ref', $executed->document_ref)->sole();
        $this->assertSame('PGDASD-DAS-202601.pdf', $artifact->original_name);
        $this->assertSame($account->id, $artifact->account_id);
        $this->assertSame($client->id, $artifact->client_id);
        $this->assertSame($enrollment->id, $artifact->enrollment_id);

        $this->assertCount(1, $transport->callCalls);
        $this->assertSame('/Emitir', $transport->callCalls[0]['path']);
        $this->assertSame('GERARDAS12', $transport->callCalls[0]['envelope']['pedidoDados']['idServico']);
        $this->assertSame(
            ['periodo_apuracao' => '202601', 'data_consolidacao' => '2026-02-10'],
            json_decode($transport->callCalls[0]['envelope']['pedidoDados']['dados'], true),
        );
        $this->assertSame('action-pgdas-key', $transport->callCalls[0]['options']['idempotency_key']);

        $this->assertSame('monitoring.das.requested', AuditLog::query()->where('action', 'monitoring.das.requested')->sole()->action);
        $finished = AuditLog::query()->where('action', 'monitoring.das.finished')->sole();
        $this->assertSame('succeeded', $finished->metadata['status']);
        $this->assertSame($client->id, $finished->metadata['client_id']);

        Event::assertDispatched(SerproActionFinished::class, fn (SerproActionFinished $event): bool => $event->context['status'] === 'succeeded'
            && $event->context['operation_code'] === 'GERARDAS12'
            && $event->context['terminal'] === true);
    }

    public function test_parcelment_emission_sets_the_installment_guide_ref(): void
    {
        Queue::fake();
        Storage::fake('local');

        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $order = ParcelmentOrder::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'modality' => 'PARCSN',
        ]);
        $installment = ParcelmentInstallment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'number' => 3,
            'guide_ref' => null,
        ]);
        $this->grantProcuration($client, '00076');

        $pdf = '%PDF-1.4 parcelment-das-canary';
        $this->openTransport($account, [[
            'status' => 200,
            'body' => ['pdf' => base64_encode($pdf)],
        ]]);

        $action = $this->executor()->request(
            $client,
            'GERARDAS161',
            'action-parcelment-key',
            true,
            [],
            null,
            $installment,
        );

        $this->assertSame('PARCSN', $action->modality);
        $this->assertSame($installment->id, $action->installment_id);

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Succeeded, $executed->status);
        $installment->refresh();
        $this->assertSame($executed->document_ref, $installment->guide_ref);
        $this->assertSame($pdf, app(ArtifactStore::class)->get((string) $installment->guide_ref));
    }

    public function test_replaying_a_key_still_returns_the_action_after_the_transport_closes(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, []);

        $first = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-replay-gated-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        SerproSettings::current()->update(['transport_approved' => false]);

        $replay = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-replay-gated-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, SerproServiceRequest::query()->count());
    }

    public function test_an_undecodable_pdf_is_recorded_as_an_artifact_failure(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, [[
            'status' => 200,
            'body' => ['pdf' => 'AAAAAAAAA'],
        ]]);

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-decode-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Succeeded, $executed->status);
        $this->assertNull($executed->document_ref);
        $this->assertSame('failed', $executed->metadata['artifact']['status']);
        $this->assertSame('decode_failed', $executed->metadata['artifact']['reason']);
    }

    public function test_an_unexpected_settlement_failure_ends_the_action_as_failed(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, [[
            'status' => 200,
            'body' => ['pdf' => base64_encode('%PDF-1.4 broken-store')],
        ]]);

        $this->app->instance(ArtifactStore::class, new class implements ArtifactStore
        {
            public function put(string $contents, array $metadata = []): array
            {
                throw new \RuntimeException('unexpected storage driver failure');
            }

            public function get(string $ref): ?string
            {
                return null;
            }

            public function delete(string $ref): bool
            {
                return false;
            }

            public function exists(string $ref): bool
            {
                return false;
            }
        });

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-settlement-failure-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Failed, $executed->status);
        $this->assertSame('action_settlement_failed', $executed->metadata['error_code']);
        $this->assertNull($executed->document_ref);
    }

    public function test_parcelment_emission_refuses_a_modality_mismatch(): void
    {
        Queue::fake();

        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $order = ParcelmentOrder::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'modality' => 'PARCSN',
        ]);
        $installment = ParcelmentInstallment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
        ]);
        $this->grantProcuration($client, '00076');
        $transport = $this->openTransport($account, []);

        $this->assertRefused('modality_mismatch', fn () => $this->executor()->request(
            $client,
            'GERARDAS171',
            'action-modality-key',
            true,
            [],
            null,
            $installment,
        ));

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
    }

    public function test_execute_polls_the_protocol_instead_of_resending_the_emission(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');

        $transport = $this->openTransport($account, [
            ['status' => 202, 'body' => ['protocol' => 'PROT-DAS-1']],
            ['status' => 200, 'body' => ['obtained' => true, 'pdf' => base64_encode('%PDF-1.4 polled-das')]],
        ]);

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-poll-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $waiting = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Pending, $waiting->status);
        $this->assertSame('PROT-DAS-1', $waiting->protocol);

        // The protocol poll respects the persisted readiness (backoff when
        // the response carries no ETA).
        $this->travel(16)->seconds();

        $done = $this->executor()->execute($waiting);

        $this->assertSame(SerproActionStatus::Succeeded, $done->status);
        $this->assertSame('PROT-DAS-1', $done->protocol);
        $this->assertCount(2, $transport->callCalls);
        $this->assertSame(
            ['protocol' => 'PROT-DAS-1', 'poll' => true],
            json_decode($transport->callCalls[1]['envelope']['pedidoDados']['dados'], true),
        );
    }

    public function test_rate_limited_and_timeout_classify_as_retryable_outcomes(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');

        $transport = $this->openTransport($account, [
            ['status' => 429, 'body' => [], 'headers' => ['Retry-After' => '45']],
        ]);

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-rate-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::RateLimited, $executed->status);
        $this->assertSame(45, $executed->metadata['retry_after']);
        $this->assertNotNull($executed->metadata['next_attempt_at']);
        $this->assertNull($executed->document_ref);

        // A retryable action refuses to send traffic before its readiness.
        $again = $this->executor()->execute($executed);
        $this->assertCount(1, $transport->callCalls);
        $this->assertSame(SerproActionStatus::RateLimited, $again->status);
    }

    public function test_a_thrown_transport_timeout_is_a_retryable_pending_action(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');

        $transport = $this->openTransport($account, []);
        $transport->onCall = fn (): array => throw new ConnectionException('timeout-canary');

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-timeout-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Pending, $executed->status);
        $this->assertSame('timeout', $executed->metadata['classification']['code']);
        $this->assertNotNull($executed->metadata['next_attempt_at']);
    }

    public function test_a_storage_failure_is_recorded_without_inventing_success(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, [[
            'status' => 200,
            'body' => ['pdf' => base64_encode('%PDF-1.4 lost-das')],
        ]]);

        $this->app->instance(ArtifactStore::class, new class implements ArtifactStore
        {
            public function put(string $contents, array $metadata = []): array
            {
                throw new ArtifactStorageUnavailableException('physical /var/secret path');
            }

            public function get(string $ref): ?string
            {
                throw new ArtifactStorageUnavailableException;
            }

            public function delete(string $ref): bool
            {
                throw new ArtifactStorageUnavailableException;
            }

            public function exists(string $ref): bool
            {
                throw new ArtifactStorageUnavailableException;
            }
        });

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-storage-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Succeeded, $executed->status);
        $this->assertNull($executed->document_ref);
        $this->assertSame('failed', $executed->metadata['artifact']['status']);
        $this->assertSame('storage_unavailable', $executed->metadata['artifact']['reason']);
        $this->assertSame(0, MonitoringArtifact::query()->count());
    }

    public function test_a_rejected_emission_is_terminal_and_never_retries(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $transport = $this->openTransport($account, [['status' => 400, 'body' => ['message' => 'canary']]]);

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-rejected-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $executed = $this->executor()->execute($action);

        $this->assertSame(SerproActionStatus::Rejected, $executed->status);
        $this->assertTrue($executed->status->isTerminal());

        $again = $this->executor()->execute($executed);

        $this->assertSame(SerproActionStatus::Rejected, $again->status);
        $this->assertCount(1, $transport->callCalls);
    }

    public function test_action_job_uses_the_serpro_queue_with_the_documented_tries_and_backoff(): void
    {
        Queue::fake();

        $action = SerproServiceRequest::factory()->create();

        ExecuteSerproActionJob::dispatch($action->id);

        Queue::assertPushedOn('serpro', ExecuteSerproActionJob::class);
        Queue::assertPushed(ExecuteSerproActionJob::class, fn (ExecuteSerproActionJob $job): bool => $job->connection === 'serpro'
            && $job->queue === 'serpro'
            && $job->actionId === $action->id
            && $job->tries === 8
            && $job->backoff() === [15, 60, 300, 900]);
    }

    public function test_action_job_executes_the_action_identified_by_the_opaque_id(): void
    {
        Storage::fake('local');

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, [[
            'status' => 200,
            'body' => ['pdf' => base64_encode('%PDF-1.4 job-das')],
        ]]);

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-job-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $job = (new ExecuteSerproActionJob($action->id))->withFakeQueueInteractions();
        $job->handle($this->executor());

        $action->refresh();

        $this->assertSame(SerproActionStatus::Succeeded, $action->status);
        $job->assertNotReleased();
    }

    public function test_action_job_releases_retryable_actions_by_their_persisted_delay(): void
    {
        $this->freezeTime();

        try {
            [$account, $client, $enrollment] = $this->context();
            $this->grantProcuration($client, '00146');
            $this->openTransport($account, [
                ['status' => 429, 'body' => [], 'headers' => ['Retry-After' => '45']],
            ]);

            $action = $this->executor()->request(
                $client,
                'GERARDAS12',
                'action-job-release-key',
                true,
                ['periodo_apuracao' => '202601'],
                $enrollment,
            );

            $job = (new ExecuteSerproActionJob($action->id))->withFakeQueueInteractions();
            $job->handle($this->executor());

            $this->assertSame(SerproActionStatus::RateLimited, $action->refresh()->status);
            $job->assertReleased(45);
        } finally {
            $this->travelBack();
        }
    }

    public function test_action_job_failed_marks_the_action_queue_attempts_exhausted(): void
    {
        Queue::fake();
        Event::fake([SerproActionFinished::class]);

        [$account, $client, $enrollment] = $this->context();
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, []);

        $action = $this->executor()->request(
            $client,
            'GERARDAS12',
            'action-job-failed-key',
            true,
            ['periodo_apuracao' => '202601'],
            $enrollment,
        );

        $job = (new ExecuteSerproActionJob($action->id))->withFakeQueueInteractions();
        $job->failed(new \RuntimeException('canary'));

        $this->assertSame(SerproActionStatus::Failed, $action->refresh()->status);
        $this->assertSame(ExecuteSerproActionJob::ERROR_EXHAUSTED, $action->metadata['error_code']);

        Event::assertDispatched(SerproActionFinished::class, fn (SerproActionFinished $event): bool => $event->context['status'] === 'failed'
            && $event->context['error_code'] === ExecuteSerproActionJob::ERROR_EXHAUSTED);
    }

    public function test_refused_requests_are_audited_without_secret_content(): void
    {
        [, $client, $enrollment] = $this->context();
        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        try {
            $this->executor()->request(
                $client,
                'GERARDAS12',
                'action-audit-key',
                true,
                ['periodo_apuracao' => '202601'],
                $enrollment,
            );
        } catch (SerproBlockedException) {
            // The factual refusal is asserted by its own tests.
        }

        $log = AuditLog::query()->where('action', 'monitoring.das.requested')->sole();

        $this->assertSame('refused', $log->metadata['outcome']);
        $this->assertSame('serpro_gated', $log->metadata['reason']);
        $this->assertStringNotContainsString('secret:', (string) json_encode($log->metadata));
    }

    private function executor(): SerproActionExecutor
    {
        return app(SerproActionExecutor::class);
    }

    /**
     * @return array{0: Account, 1: Client, 2: MonitoringEnrollment}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $enrollment = $this->pgdasEnrollment($account, $client);

        return [$account, $client, $enrollment];
    }

    private function pgdasEnrollment(Account $account, Client $client): MonitoringEnrollment
    {
        $definition = MonitoringDefinition::factory()->create([
            'id' => 'pgdas-declaracoes',
            'operations' => ['CONSDECLARACAO13'],
            'procuration_codes' => ['00146'],
            'requires_procuracao' => true,
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

    private function grantProcuration(Client $client, string $code): PowerOfAttorney
    {
        return PowerOfAttorney::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'code' => $code,
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addYear(),
        ]);
    }

    /**
     * @param  list<array{status: int, body: array<string, mixed>, headers?: array<string, mixed>}>  $callResponses
     */
    private function openTransport(Account $account, array $callResponses, bool $withAuthor = true): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $ref = 'secret:action-'.uniqid();
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
            ]);
        }

        $transport = new FakeSerproTransport([], $callResponses);
        $this->app->instance(SerproTransport::class, $transport);

        return $transport;
    }

    private function assertRefused(string $expected, callable $callback): void
    {
        try {
            $callback();
        } catch (SerproBlockedException $exception) {
            $this->assertSame($expected, $exception->getMessage());

            return;
        }

        $this->fail("Expected a factual refusal [{$expected}].");
    }
}
