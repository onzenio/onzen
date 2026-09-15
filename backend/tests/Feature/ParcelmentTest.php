<?php

namespace Tests\Feature;

use App\Contracts\ArtifactStore;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorStatus;
use App\Enums\MonitoringRunStatus;
use App\Enums\UserRole;
use App\Exceptions\ArtifactStorageUnavailableException;
use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\ConsultFixtureProvider;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MonitoringArtifact;
use App\Models\MonitoringAttempt;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Models\ParcelmentInstallment;
use App\Models\ParcelmentOrder;
use App\Models\ParcelmentPayment;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproSettings;
use App\Services\Monitoring\ParcelmentConsultProjector;
use App\Services\Monitoring\SerproExecutor;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class ParcelmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_parcelment_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('parcelment_orders', [
            'id', 'account_id', 'client_id', 'enrollment_id', 'modality', 'external_id',
            'status', 'installments_count', 'total_amount', 'competence', 'provenance',
            'operation_code', 'metadata', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('parcelment_installments', [
            'id', 'account_id', 'client_id', 'order_id', 'external_id', 'number', 'status',
            'amount', 'due_date', 'paid_at', 'guide_ref', 'provenance', 'metadata',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('parcelment_payments', [
            'id', 'account_id', 'client_id', 'installment_id', 'external_id', 'status',
            'amount', 'paid_at', 'receipt_ref', 'provenance', 'metadata',
            'created_at', 'updated_at',
        ]));
    }

    public function test_each_of_the_eight_modalities_normalizes_orders_installments_and_payments(): void
    {
        [$account, $client, $enrollment] = $this->context();

        foreach (ConsultCatalog::PARCELMENT_OPERATIONS as $definitionId => $operations) {
            $modality = strtoupper($definitionId);

            foreach ($operations as $operation) {
                if (! ConsultCatalog::isParcelmentConsult($operation)) {
                    continue;
                }

                $this->project($enrollment, $operation, 'key-'.$operation);
            }

            $orders = ParcelmentOrder::query()
                ->where('account_id', $account->id)
                ->where('modality', $modality)
                ->get();

            $this->assertCount(2, $orders, "Modality {$modality} must project its two orders.");
            $this->assertSame(
                [$client->id],
                $orders->pluck('client_id')->unique()->values()->all(),
                "Modality {$modality} must link every order to the Client.",
            );

            $installments = ParcelmentInstallment::query()
                ->whereIn('order_id', $orders->pluck('id'))
                ->get();
            $this->assertCount(2, $installments, "Modality {$modality} must project its two installments.");
            $this->assertSame(
                [$client->id],
                $installments->pluck('client_id')->unique()->values()->all(),
            );

            $payments = ParcelmentPayment::query()
                ->whereIn('installment_id', $installments->pluck('id'))
                ->get();
            $this->assertCount(1, $payments, "Modality {$modality} must project its payment.");
            $this->assertSame([$client->id], $payments->pluck('client_id')->unique()->values()->all());
            $this->assertSame('pago', $payments->first()->status);
        }

        $this->assertSame(16, ParcelmentOrder::query()->count());
        $this->assertSame(16, ParcelmentInstallment::query()->count());
        $this->assertSame(8, ParcelmentPayment::query()->count());
    }

    public function test_projection_normalizes_detail_fields_and_keeps_missing_fields_neutral(): void
    {
        [$account, $client, $enrollment] = $this->context();

        $this->project($enrollment, 'PEDIDOSPARC163', 'pedidos-key');

        $order = ParcelmentOrder::query()
            ->where('account_id', $account->id)
            ->where('external_id', 'PARC-001')
            ->sole();

        $this->assertSame('PARCSN', $order->modality);
        $this->assertSame($client->id, $order->client_id);
        $this->assertSame($enrollment->id, $order->enrollment_id);
        $this->assertSame('PEDIDOSPARC163', $order->operation_code);
        $this->assertSame('2026-01-01', $order->competence?->toDateString());

        // The detail operation converges on the same order and brings the
        // installments; absent fields stay neutral (port fidelity: the last
        // operation overwrites with its own facts, including nulls).
        $this->project($enrollment, 'OBTERPARC164', 'obter-key');
        $order->refresh();

        $this->assertSame('ativo', $order->status);
        $this->assertSame(12, $order->installments_count);
        $this->assertSame('1200.50', $order->total_amount);
        $this->assertSame('OBTERPARC164', $order->operation_code);

        $installment = ParcelmentInstallment::query()
            ->where('order_id', $order->id)
            ->sole();

        $this->assertSame(1, $installment->number);
        $this->assertSame('PARC-001-1', $installment->external_id);
        $this->assertSame('available', $installment->status);
        $this->assertSame('100.04', $installment->amount);
        $this->assertSame('2026-02-28', $installment->due_date?->toDateString());
        $this->assertSame(0, ParcelmentPayment::query()->count());

        // Missing fields stay neutral: never invented.
        $this->project($enrollment, 'PARCELASPARAGERAR222', 'neutral-key', [
            'source' => 'fixture',
            'operation_code' => 'PARCELASPARAGERAR222',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => [
                'numero' => 'PARC-NEUTRO',
                'parcelas' => [[
                    'numeroParcela' => 3,
                    'id' => 'PARC-NEUTRO-3',
                    'vencimento' => '2026-03-10',
                ]],
            ]],
        ]);

        $neutral = ParcelmentOrder::query()
            ->where('account_id', $account->id)
            ->where('external_id', 'PARC-NEUTRO')
            ->sole();

        $this->assertSame('PERTMEI', $neutral->modality);
        $this->assertNull($neutral->status);
        $this->assertNull($neutral->installments_count);
        $this->assertNull($neutral->total_amount);
        $this->assertNull($neutral->competence);

        $neutralInstallment = ParcelmentInstallment::query()
            ->where('order_id', $neutral->id)
            ->sole();

        $this->assertSame(3, $neutralInstallment->number);
        $this->assertNull($neutralInstallment->status);
        $this->assertNull($neutralInstallment->amount);
        $this->assertSame('2026-03-10', $neutralInstallment->due_date?->toDateString());
    }

    public function test_installment_level_payment_facts_are_not_taken_from_the_order(): void
    {
        [, $client, $enrollment] = $this->context();

        $this->project($enrollment, 'OBTERPARC194', 'installment-payment', [
            'source' => 'fixture',
            'operation_code' => 'OBTERPARC194',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => [
                'numero' => 'PARC-ORDER-194',
                'id' => 'PARC-ORDER-194',
                'status' => 'ativo',
                'valorTotal' => 200.00,
                'parcelas' => [[
                    'numeroParcela' => 2,
                    'id' => 'PARC-ORDER-194-2',
                    'status' => 'pago',
                    'valor' => 100.04,
                    'vencimento' => '2026-03-31',
                    'numeroDas' => '85800000000000000999',
                    'dataPagamento' => '2026-03-20',
                ]],
            ]],
        ]);

        // The payment signal lives in the installment row: its facts must
        // come from the installment, never from the order-level fallback.
        $payment = ParcelmentPayment::query()->sole();

        $this->assertSame('PARC-ORDER-194-2', $payment->external_id);
        $this->assertSame('pago', $payment->status);
        $this->assertSame('100.04', $payment->amount);
        $this->assertSame('2026-03-20', $payment->paid_at?->toDateString());
        $this->assertSame($client->id, $payment->client_id);
    }

    public function test_order_level_payment_facts_still_normalize_from_the_order(): void
    {
        [, , $enrollment] = $this->context();

        $this->project($enrollment, 'OBTERPARC204', 'order-payment', [
            'source' => 'fixture',
            'operation_code' => 'OBTERPARC204',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => [
                'numero' => 'PARC-ORDER-204',
                'id' => 'PARC-ORDER-204',
                'status' => 'ativo',
                'numeroDas' => '85800000000000000777',
                'dataPagamento' => '2026-04-15',
                'parcelas' => [[
                    'numeroParcela' => 1,
                    'id' => 'PARC-ORDER-204-1',
                    'status' => 'available',
                    'valor' => 50.00,
                    'vencimento' => '2026-04-30',
                ]],
            ]],
        ]);

        // Legacy port kept: an order-level payment signal normalizes from the
        // order payload, even when a payment-free installment row exists.
        $payment = ParcelmentPayment::query()->sole();

        $this->assertSame('PARC-ORDER-204', $payment->external_id);
        $this->assertSame('ativo', $payment->status);
        $this->assertSame('2026-04-15', $payment->paid_at?->toDateString());
        $this->assertSame('PARC-ORDER-204-1', $payment->installment->external_id);
    }

    public function test_projection_is_idempotent_per_run(): void
    {
        [$account, $client, $enrollment] = $this->context();

        $run = $this->runFor($enrollment, 'DETPAGTOPARC175');
        $result = $this->fixtureResult('DETPAGTOPARC175');

        $projector = $this->projector();
        $projector->project($run, $result);
        $projector->project($run, $result);

        $this->assertSame(1, ParcelmentOrder::query()->count());
        $this->assertSame(1, ParcelmentInstallment::query()->count());
        $this->assertSame(1, ParcelmentPayment::query()->count());

        // A different run with the same payload converges on the same rows.
        $projector->project($this->runFor($enrollment, 'DETPAGTOPARC175', 'second-key'), $result);

        $this->assertSame(1, ParcelmentOrder::query()->count());
        $this->assertSame(1, ParcelmentInstallment::query()->count());
        $this->assertSame(1, ParcelmentPayment::query()->count());

        $order = ParcelmentOrder::query()->sole();
        $this->assertSame($account->id, $order->account_id);
        $this->assertSame($client->id, $order->client_id);
        $this->assertSame('PARCSN-ESP', $order->modality);
    }

    public function test_projection_ignores_non_parcelment_and_unknown_operations(): void
    {
        [, , $enrollment] = $this->context();

        $this->projector()->project($this->runFor($enrollment, 'RELATORIOSITFIS92'), [
            'source' => 'fixture',
            'operation_code' => 'RELATORIOSITFIS92',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => ['situacao' => 'regular']],
        ]);

        $this->projector()->project($this->runFor($enrollment, 'PEDIDOSPARC999'), [
            'source' => 'fixture',
            'operation_code' => 'PEDIDOSPARC999',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => ['pedidos' => [['id' => 'X', 'numero' => 'X']]]],
        ]);

        $this->assertSame(0, ParcelmentOrder::query()->count());
        $this->assertSame(0, ParcelmentInstallment::query()->count());
        $this->assertSame(0, ParcelmentPayment::query()->count());
    }

    public function test_successful_consult_run_projects_parcelments_and_publishes_the_snapshot(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $enrollment = $this->enrollment($account, $client, 'PEDIDOSPARC163');

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'parcel-dry-run'));

        $this->assertSame(MonitoringRunStatus::Completed, $run->status);

        $order = ParcelmentOrder::query()->sole();
        $this->assertSame($account->id, $order->account_id);
        $this->assertSame($client->id, $order->client_id);
        $this->assertSame($enrollment->id, $order->enrollment_id);
        $this->assertSame('PARCSN', $order->modality);
        $this->assertSame('PARC-001', $order->external_id);
        $this->assertSame('fixture', $order->provenance);

        $snapshot = MonitoringSnapshot::query()->where('run_id', $run->id)->sole();
        $this->assertSame('parcelment', $snapshot->family);
        $this->assertSame('PEDIDOSPARC163', $snapshot->operation_code);
        $this->assertFalse($snapshot->normalized);
    }

    public function test_rate_limited_consult_does_not_advance_parcelments(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $enrollment = $this->enrollment($account, $client, 'PEDIDOSPARC163');
        $this->openTransport($account, [['status' => 429, 'body' => ['dados' => []]]]);

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'parcel-429'));

        $this->assertSame(MonitoringRunStatus::Limited, $run->status);
        $this->assertSame(0, ParcelmentOrder::query()->count());
        $this->assertSame(0, ParcelmentInstallment::query()->count());
        $this->assertSame(0, ParcelmentPayment::query()->count());
        $this->assertSame(0, MonitoringSnapshot::query()->count());

        $attempt = MonitoringAttempt::query()->sole();
        $this->assertNotNull($attempt->retry_after);
        $this->assertGreaterThan(0, (int) $attempt->retry_after);
    }

    public function test_transient_failure_does_not_advance_parcelments(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $enrollment = $this->enrollment($account, $client, 'OBTERPARC174');
        $this->openTransport($account, [['status' => 500, 'body' => ['dados' => []]]]);

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'parcel-500'));

        $this->assertSame(MonitoringRunStatus::Transient, $run->status);
        $this->assertSame(0, ParcelmentOrder::query()->count());
        $this->assertSame(0, ParcelmentInstallment::query()->count());
        $this->assertSame(0, ParcelmentPayment::query()->count());
        $this->assertSame(0, MonitoringSnapshot::query()->count());
    }

    public function test_parcelment_reads_require_authentication(): void
    {
        $this->getJson('/api/monitoring/parcelamentos')->assertUnauthorized();
        $this->getJson('/api/monitoring/parcelamentos/1')->assertUnauthorized();
        $this->getJson('/api/monitoring/parcelamentos/1/parcelas')->assertUnauthorized();
        $this->getJson('/api/monitoring/parcelas/1/pagamentos')->assertUnauthorized();
        $this->getJson('/api/monitoring/parcelas/1/guia/download')->assertUnauthorized();
    }

    public function test_listing_is_paginated_and_scoped_to_the_account(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();

        $oldest = $this->order($account, $client, ['status' => 'ativo', 'installments_count' => null]);
        $middle = $this->order($account, $client, ['status' => 'ativo']);
        $newest = $this->order($account, $client, ['status' => 'negociado']);
        $this->installment($account, $client, $oldest, ['paid_at' => now()->subDay()]);
        $this->installment($account, $client, $oldest, ['number' => 2]);

        $foreignAccount = $this->createAccount();
        $foreignClient = Client::factory()->for($foreignAccount, 'account')->create();
        $foreign = $this->order($foreignAccount, $foreignClient);

        $response = $this->actingAs($actor)
            ->getJson('/api/monitoring/parcelamentos?per_page=2')
            ->assertOk();

        $response->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.0.client_id', $client->id)
            ->assertJsonPath('data.0.client.razao_social', $client->razao_social);

        $second = $this->actingAs($actor)
            ->getJson('/api/monitoring/parcelamentos?per_page=2&page=2')
            ->assertOk();

        $second->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $oldest->id)
            ->assertJsonPath('data.0.installments_count', 2)
            ->assertJsonPath('data.0.paid_installments', 1);

        $ids = collect($response->json('data'))->pluck('id')
            ->merge(collect($second->json('data'))->pluck('id'));

        $this->assertNotContains($foreign->id, $ids->all());
    }

    public function test_listing_filters_by_modalidade_client_and_status(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();
        $otherClient = Client::factory()->for($account, 'account')->create();

        $target = $this->order($account, $client, ['modality' => 'PARCSN', 'status' => 'ativo']);
        $this->order($account, $client, ['modality' => 'PARCMEI', 'status' => 'ativo']);
        $this->order($account, $client, ['modality' => 'PARCSN', 'status' => 'encerrado']);
        $this->order($account, $otherClient, ['modality' => 'PARCSN', 'status' => 'ativo']);

        $byModality = $this->actingAs($actor)
            ->getJson('/api/monitoring/parcelamentos?modalidade=PARCSN')
            ->assertOk();
        $byModality->assertJsonPath('total', 3);

        $byClient = $this->actingAs($actor)
            ->getJson('/api/monitoring/parcelamentos?client_id='.$otherClient->id)
            ->assertOk();
        $byClient->assertJsonPath('total', 1);

        $byStatus = $this->actingAs($actor)
            ->getJson('/api/monitoring/parcelamentos?modalidade=PARCSN&client_id='.$client->id.'&status=ativo')
            ->assertOk();
        $byStatus->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $target->id);

        $this->actingAs($actor)
            ->getJson('/api/monitoring/parcelamentos?modalidade=NAO-EXISTE')
            ->assertUnprocessable();
    }

    public function test_listing_never_exposes_metadata_or_internal_refs(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();

        $order = $this->order($account, $client, [
            'metadata' => ['raw' => 'RAW-METADATA-MARKER', 'consumer_secret' => 'SECRET-MARKER'],
        ]);
        $this->installment($account, $client, $order, [
            'guide_ref' => 'guide-ref-canary',
            'metadata' => ['raw' => 'INSTALLMENT-RAW-MARKER'],
        ]);

        $response = $this->actingAs($actor)->getJson('/api/monitoring/parcelamentos')->assertOk();

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('RAW-METADATA-MARKER', $content);
        $this->assertStringNotContainsString('SECRET-MARKER', $content);
        $this->assertStringNotContainsString('guide-ref-canary', $content);
        $this->assertStringNotContainsString('INSTALLMENT-RAW-MARKER', $content);
    }

    public function test_detail_exposes_installments_and_payments_with_the_client(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::User]);
        $client = Client::factory()->for($account, 'account')->create();

        $order = $this->order($account, $client, ['status' => 'ativo']);
        $installment = $this->installment($account, $client, $order, [
            'number' => 1,
            'guide_ref' => 'guide-ref-present',
        ]);
        $payment = $this->payment($account, $client, $installment, [
            'status' => 'pago',
            'receipt_ref' => 'receipt-ref-present',
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelamentos/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.status', 'ativo')
            ->assertJsonPath('data.installments.0.id', $installment->id)
            ->assertJsonPath('data.installments.0.number', 1)
            ->assertJsonPath('data.installments.0.guide_available', true)
            ->assertJsonPath('data.installments.0.payments.0.id', $payment->id)
            ->assertJsonPath('data.installments.0.payments.0.receipt_available', true);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('guide-ref-present', $content);
        $this->assertStringNotContainsString('receipt-ref-present', $content);
    }

    public function test_installments_are_paginated_and_guide_availability_is_factual(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();
        $order = $this->order($account, $client);

        $first = $this->installment($account, $client, $order, [
            'number' => 1,
            'guide_ref' => 'guide-ref-present',
        ]);
        $this->installment($account, $client, $order, ['number' => 2]);
        $this->installment($account, $client, $order, ['number' => 3]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelamentos/{$order->id}/parcelas?per_page=2")
            ->assertOk();

        $response->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.guide_available', true)
            ->assertJsonPath('data.1.guide_available', false);

        $second = $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelamentos/{$order->id}/parcelas?per_page=2&page=2")
            ->assertOk();

        $second->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 3)
            ->assertJsonPath('data.0.guide_available', false);
    }

    public function test_payments_are_paginated(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();
        $order = $this->order($account, $client);
        $installment = $this->installment($account, $client, $order);

        $first = $this->payment($account, $client, $installment, ['status' => 'pago']);
        $second = $this->payment($account, $client, $installment, ['status' => 'estornado']);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelas/{$installment->id}/pagamentos?per_page=1")
            ->assertOk();

        $response->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.status', 'estornado');

        $page = $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelas/{$installment->id}/pagamentos?per_page=1&page=2")
            ->assertOk();

        $page->assertJsonPath('data.0.id', $first->id);
    }

    public function test_cross_account_returns_404_for_each_parcelment_route(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);

        $foreignAccount = $this->createAccount();
        $foreignClient = Client::factory()->for($foreignAccount, 'account')->create();
        $foreignOrder = $this->order($foreignAccount, $foreignClient);
        $foreignInstallment = $this->installment($foreignAccount, $foreignClient, $foreignOrder);

        $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelamentos/{$foreignOrder->id}")
            ->assertNotFound();

        $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelamentos/{$foreignOrder->id}/parcelas")
            ->assertNotFound();

        $this->actingAs($actor)
            ->getJson("/api/monitoring/parcelas/{$foreignInstallment->id}/pagamentos")
            ->assertNotFound();

        $this->actingAs($actor)
            ->getJson(URL::signedRoute('monitoring.parcelments.guide', ['installment' => $foreignInstallment->id]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->getJson('/api/monitoring/parcelamentos?client_id='.$foreignClient->id)
            ->assertNotFound();
    }

    public function test_guide_download_streams_an_existing_artifact_without_emission_and_audits(): void
    {
        Storage::fake('local');

        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();
        $order = $this->order($account, $client);

        $contents = '%PDF-1.4 parcelment-guide-canary';
        $stored = app(ArtifactStore::class)->put($contents, [
            'account_id' => $account->id,
            'client_id' => $client->id,
            'kind' => 'pdf',
            'original_name' => 'guia-das.pdf',
        ]);

        $installment = $this->installment($account, $client, $order, ['guide_ref' => $stored['ref']]);

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $response = $this->actingAs($actor)
            ->get(URL::signedRoute('monitoring.parcelments.guide', ['installment' => $installment->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($contents, $response->streamedContent());

        $log = AuditLog::query()->where('action', 'monitoring.parcelment.guide.downloaded')->sole();
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($account->id, $log->origin_account_id);
        $this->assertSame($stored['ref'], $log->metadata['ref']);
        $this->assertSame($stored['hash_sha256'], $log->metadata['hash_sha256']);
        $this->assertStringNotContainsString($contents, (string) json_encode($log->metadata));

        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame(0, MonitoringRun::query()->count());
    }

    public function test_guide_download_of_an_absent_guide_returns_404_without_emission(): void
    {
        Storage::fake('local');

        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();
        $order = $this->order($account, $client);

        $withoutGuide = $this->installment($account, $client, $order, ['guide_ref' => null]);
        $withUnknownRef = $this->installment($account, $client, $order, [
            'number' => 2,
            'guide_ref' => 'ref-que-nao-existe',
        ]);

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $missing = $this->actingAs($actor)
            ->getJson(URL::signedRoute('monitoring.parcelments.guide', ['installment' => $withoutGuide->id]))
            ->assertNotFound()
            ->json();

        $unknown = $this->actingAs($actor)
            ->getJson(URL::signedRoute('monitoring.parcelments.guide', ['installment' => $withUnknownRef->id]))
            ->assertNotFound()
            ->json();

        $this->assertSame($missing, $unknown);
        $this->assertSame([], $transport->callCalls);
        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame(0, MonitoringRun::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'monitoring.parcelment.guide.downloaded']);
    }

    public function test_guide_download_storage_outage_returns_a_retryable_503(): void
    {
        Storage::fake('local');

        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();
        $order = $this->order($account, $client);
        $installment = $this->installment($account, $client, $order, ['guide_ref' => 'guide-ref-outage']);
        MonitoringArtifact::factory()->create([
            'account_id' => $account->id,
            'ref' => 'guide-ref-outage',
        ]);

        $this->app->instance(ArtifactStore::class, new class implements ArtifactStore
        {
            public function put(string $contents, array $metadata = []): array
            {
                throw new ArtifactStorageUnavailableException;
            }

            public function get(string $ref): ?string
            {
                throw new ArtifactStorageUnavailableException('physical /var/secret/artifacts path');
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

        $response = $this->actingAs($actor)
            ->getJson(URL::signedRoute('monitoring.parcelments.guide', ['installment' => $installment->id]));

        $response->assertStatus(503)
            ->assertJsonPath('code', 'ARTIFACT_STORAGE_UNAVAILABLE')
            ->assertJsonPath('retryable', true);

        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertStringNotContainsString('/var/secret/artifacts', (string) $response->getContent());

        $log = AuditLog::query()->where('action', 'monitoring.parcelment.guide.download_failed')->sole();
        $this->assertSame($installment->guide_ref, $log->metadata['ref']);
    }

    public function test_guide_download_is_forbidden_for_a_user_role(): void
    {
        Storage::fake('local');

        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::User]);
        $client = Client::factory()->for($account, 'account')->create();
        $order = $this->order($account, $client);
        $installment = $this->installment($account, $client, $order, ['guide_ref' => 'guide-ref-role']);
        MonitoringArtifact::factory()->create([
            'account_id' => $account->id,
            'ref' => 'guide-ref-role',
        ]);

        $this->actingAs($user)
            ->getJson(URL::signedRoute('monitoring.parcelments.guide', ['installment' => $installment->id]))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'monitoring.parcelment.guide.downloaded']);
    }

    /**
     * @return array{0: Account, 1: Client, 2: MonitoringEnrollment}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        CurrentAccount::set($account->id);
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $definition = MonitoringDefinition::factory()->create([
            'operations' => ['PEDIDOSPARC163'],
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

        return [$account, $client, $enrollment];
    }

    private function projector(): ParcelmentConsultProjector
    {
        return app(ParcelmentConsultProjector::class);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function project(MonitoringEnrollment $enrollment, string $operation, string $key, ?array $result = null): void
    {
        $run = $this->runFor($enrollment, $operation, $key);
        $this->projector()->project($run, $result ?? $this->fixtureResult($operation));
    }

    private function runFor(MonitoringEnrollment $enrollment, string $operation, ?string $key = null): MonitoringRun
    {
        return MonitoringRun::factory()->create([
            'account_id' => $enrollment->account_id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => $operation,
            'idempotency_key' => $key ?? ('key-'.$operation.'-'.uniqid()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureResult(string $operation): array
    {
        $fixture = app(ConsultFixtureProvider::class)->load($operation);

        return [
            'source' => 'fixture',
            'operation_code' => $operation,
            'http_status' => (int) ($fixture['http_status'] ?? 200),
            'protocol' => null,
            'eta' => null,
            'body' => is_array($fixture['body'] ?? null) ? $fixture['body'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(Account $account, Client $client, array $attributes = []): ParcelmentOrder
    {
        return ParcelmentOrder::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function installment(Account $account, Client $client, ParcelmentOrder $order, array $attributes = []): ParcelmentInstallment
    {
        return ParcelmentInstallment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payment(Account $account, Client $client, ParcelmentInstallment $installment, array $attributes = []): ParcelmentPayment
    {
        return ParcelmentPayment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'installment_id' => $installment->id,
            ...$attributes,
        ]);
    }

    private function executor(): SerproExecutor
    {
        return app(SerproExecutor::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrollment(Account $account, Client $client, string $operation, array $attributes = []): MonitoringEnrollment
    {
        CurrentAccount::set($account->id);
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

    /**
     * @param  list<array<string, mixed>>  $callResponses
     */
    private function openTransport(Account $account, array $callResponses): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $ref = 'secret:parcel-'.uniqid();
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
