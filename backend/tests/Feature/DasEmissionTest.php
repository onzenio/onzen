<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorStatus;
use App\Enums\UserRole;
use App\Jobs\ExecuteSerproActionJob;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class DasEmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_emission_routes_require_authentication(): void
    {
        $this->postJson('/api/monitoring/enrollments/1/gerar-das')->assertUnauthorized();
        $this->postJson('/api/monitoring/parcelas/1/gerar-das')->assertUnauthorized();
        $this->getJson('/api/monitoring/actions/1')->assertUnauthorized();
    }

    public function test_user_role_cannot_emit_and_creates_no_action(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $user = $this->createUser($account, ['role' => UserRole::User]);

        $this->actingAs($user)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertForbidden();

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'monitoring.das.requested']);
    }

    public function test_cross_account_resources_answer_an_indistinguishable_404(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);

        $foreign = $this->createAccount();
        $foreignClient = Client::factory()->for($foreign, 'account')->create();
        $foreignEnrollment = $this->pgdasEnrollment($foreign, $foreignClient);
        $foreignOrder = ParcelmentOrder::factory()->create([
            'account_id' => $foreign->id,
            'client_id' => $foreignClient->id,
            'modality' => 'PARCSN',
        ]);
        $foreignInstallment = ParcelmentInstallment::factory()->create([
            'account_id' => $foreign->id,
            'client_id' => $foreignClient->id,
            'order_id' => $foreignOrder->id,
        ]);
        $foreignAction = SerproServiceRequest::factory()->create([
            'account_id' => $foreign->id,
            'client_id' => $foreignClient->id,
        ]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$foreignEnrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertNotFound();

        $this->actingAs($actor)
            ->postJson("/api/monitoring/parcelas/{$foreignInstallment->id}/gerar-das", $this->parcelmentPayload())
            ->assertNotFound();

        $this->actingAs($actor)
            ->getJson("/api/monitoring/actions/{$foreignAction->id}")
            ->assertNotFound();

        $this->assertSame(0, SerproServiceRequest::query()->where('account_id', $account->id)->count());
    }

    public function test_emission_validates_period_key_and_confirmation(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", [
                'periodo_apuracao' => '202613',
                'idempotency_key' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['periodo_apuracao', 'idempotency_key', 'confirmed']);

        $this->assertSame(0, SerproServiceRequest::query()->count());
    }

    public function test_emission_is_limited_to_pgdas_enrollments(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $definition = MonitoringDefinition::factory()->create([
            'id' => 'parcsn',
            'operations' => ['PEDIDOSPARC163'],
        ]);
        $enrollment = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
        ]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertStatus(422)
            ->assertJsonPath('error', 'emission_definition_mismatch');

        $this->assertSame(0, SerproServiceRequest::query()->count());
    }

    public function test_confirmed_pgdas_emission_creates_the_action_and_dispatches_the_job(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, []);

        $response = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertStatus(202)
            ->assertJsonPath('data.operation_code', 'GERARDAS12')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.document_available', false);

        $action = SerproServiceRequest::query()->sole();

        $this->assertSame($response->json('data.id'), $action->id);
        $this->assertSame($account->id, $action->account_id);
        $this->assertSame($client->id, $action->client_id);
        $this->assertSame($enrollment->id, $action->enrollment_id);
        $this->assertSame($actor->id, $action->requested_by_user_id);
        $this->assertSame(['periodo_apuracao' => '202601', 'data_consolidacao' => now()->toDateString()], $action->parameters);

        Queue::assertPushed(ExecuteSerproActionJob::class, 1);

        $audit = AuditLog::query()->where('action', 'monitoring.das.requested')->sole();
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame('accepted', $audit->metadata['outcome']);
        $this->assertSame($client->id, $audit->metadata['client_id']);
    }

    public function test_repeating_the_key_returns_the_existing_action_without_a_second_job(): void
    {
        Queue::fake();

        [$account, $client, $enrollment] = $this->context();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $this->grantProcuration($client, '00146');
        $this->openTransport($account, []);

        $first = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertStatus(202);

        $second = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SerproServiceRequest::query()->count());
        Queue::assertPushed(ExecuteSerproActionJob::class, 1);
    }

    public function test_reusing_a_key_for_another_installment_conflicts(): void
    {
        Queue::fake();

        [$account, $client] = $this->parcelmentContext();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $this->grantProcuration($client, '00076');
        $this->openTransport($account, []);

        $order = ParcelmentOrder::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'modality' => 'PARCSN',
        ]);
        $first = ParcelmentInstallment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'number' => 1,
        ]);
        $secondInstallment = ParcelmentInstallment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'number' => 2,
        ]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/parcelas/{$first->id}/gerar-das", $this->parcelmentPayload())
            ->assertStatus(202);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/parcelas/{$secondInstallment->id}/gerar-das", $this->parcelmentPayload())
            ->assertStatus(409);

        $this->assertSame(1, SerproServiceRequest::query()->count());
    }

    public function test_transport_off_refuses_the_emission_without_creating_or_calling(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $this->grantProcuration($client, '00146');

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertStatus(422)
            ->assertJsonPath('error', 'serpro_gated');

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
    }

    public function test_missing_procurement_refuses_the_emission_factually(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $transport = $this->openTransport($account, []);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertStatus(422)
            ->assertJsonPath('error', 'outorga_pendente');

        $this->assertSame(0, SerproServiceRequest::query()->count());
        $this->assertSame([], $transport->callCalls);
    }

    public function test_pgdas_emission_exposes_and_downloads_the_pdf_artifact(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$account, $client, $enrollment] = $this->context();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $this->grantProcuration($client, '00146');

        $pdf = '%PDF-1.4 pgdas-api-canary';
        $this->openTransport($account, [[
            'status' => 200,
            'body' => ['periodoApuracao' => '202601', 'pdf' => base64_encode($pdf)],
        ]]);

        $actionId = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/gerar-das", $this->pgdasPayload())
            ->assertStatus(202)
            ->json('data.id');

        $this->execute((int) $actionId);

        $read = $this->actingAs($actor)
            ->getJson("/api/monitoring/actions/{$actionId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.document_available', true);

        $artifact = $read->json('data.artifacts.0');

        $this->assertSame('PGDASD-DAS-202601.pdf', $artifact['filename']);
        $this->assertNotEmpty($artifact['ref']);
        $this->assertNotEmpty($artifact['hash_sha256']);
        $this->assertNotEmpty($artifact['download_url']);

        $download = $this->actingAs($actor)->get($artifact['download_url']);

        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($pdf, $download->streamedContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'monitoring.artifact.downloaded']);
    }

    public function test_parcelment_emission_sets_the_guide_ref_and_the_download_route_serves_it(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$account, $client] = $this->parcelmentContext();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $this->grantProcuration($client, '00076');

        $order = ParcelmentOrder::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'modality' => 'PARCSN',
        ]);
        $installment = ParcelmentInstallment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'number' => 4,
        ]);

        $pdf = '%PDF-1.4 parcelment-api-canary';
        $this->openTransport($account, [[
            'status' => 200,
            'body' => ['pdf' => base64_encode($pdf)],
        ]]);

        $actionId = $this->actingAs($actor)
            ->postJson("/api/monitoring/parcelas/{$installment->id}/gerar-das", $this->parcelmentPayload())
            ->assertStatus(202)
            ->json('data.id');

        $this->execute((int) $actionId);

        $installment->refresh();
        $this->assertNotNull($installment->guide_ref);

        $detail = $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelamentos/{$order->id}")
            ->assertOk()
            ->json('data.installments.0');

        $this->assertTrue($detail['guide_available']);

        $download = $this->actingAs($actor)->get("/api/monitoring/parcelas/{$installment->id}/guia/download");

        $download->assertOk();
        $this->assertSame($pdf, $download->streamedContent());
    }

    public function test_action_read_payload_never_exposes_raw_metadata_or_storage_paths(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();

        $action = SerproServiceRequest::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'metadata' => [
                'classification' => ['status' => 'succeeded', 'code' => 'ok'],
                'artifact' => ['storage_path' => '/var/secret/artifacts/CANARY-PATH', 'token' => 'CANARY-TOKEN'],
            ],
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/actions/{$action->id}")
            ->assertOk();

        $encoded = (string) $response->getContent();

        $this->assertStringNotContainsString('CANARY-PATH', $encoded);
        $this->assertStringNotContainsString('CANARY-TOKEN', $encoded);
        $this->assertStringNotContainsString('classification', $encoded);
    }

    private function execute(int $actionId): void
    {
        $action = SerproServiceRequest::query()->whereKey($actionId)->sole();

        app(SerproActionExecutor::class)->execute($action);
    }

    /**
     * @return array{0: Account, 1: Client, 2: MonitoringEnrollment}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);

        return [$account, $client, $this->pgdasEnrollment($account, $client)];
    }

    /**
     * @return array{0: Account, 1: Client}
     */
    private function parcelmentContext(): array
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);

        return [$account, $client];
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
     * @return array<string, mixed>
     */
    private function pgdasPayload(): array
    {
        return [
            'periodo_apuracao' => '202601',
            'data_consolidacao' => now()->toDateString(),
            'idempotency_key' => 'das-emission-key-001',
            'confirmed' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parcelmentPayload(): array
    {
        return [
            'idempotency_key' => 'das-emission-key-001',
            'confirmed' => true,
        ];
    }

    /**
     * @param  list<array{status: int, body: array<string, mixed>, headers?: array<string, mixed>}>  $callResponses
     */
    private function openTransport(Account $account, array $callResponses): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $ref = 'secret:das-'.uniqid();
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

        $transport = new FakeSerproTransport([], $callResponses);
        $this->app->instance(SerproTransport::class, $transport);

        return $transport;
    }
}
