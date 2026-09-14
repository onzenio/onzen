<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Enums\MonitoringRunStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Models\Plan;
use App\Models\User;
use App\Services\Monitoring\QueryQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class MonitoringReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_require_authentication(): void
    {
        $this->getJson('/api/monitoring/dashboard')->assertUnauthorized();
        $this->getJson('/api/monitoring/enrollments/1/snapshots')->assertUnauthorized();
        $this->getJson('/api/monitoring/enrollments/1/changes')->assertUnauthorized();
        $this->getJson('/api/monitoring/runs')->assertUnauthorized();
        $this->getJson('/api/monitoring/alerts')->assertUnauthorized();
        $this->postJson('/api/monitoring/alerts/1/acknowledge')->assertUnauthorized();
    }

    public function test_dashboard_reports_counts_last_run_and_quota_for_the_effective_account(): void
    {
        $plan = Plan::factory()->create(['monthly_query_volume' => 10]);
        $account = $this->createAccount(['plan_id' => $plan->id]);
        $actor = $this->actor($account);

        $active = $this->enrollment($account);
        $this->enrollment($account);
        $this->enrollment($account, null, null, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
        ]);
        $this->enrollment($account, null, null, ['status' => MonitoringEnrollment::STATUS_ENDED]);

        $this->alert($account, ['status' => MonitoringAlert::STATUS_PENDING]);
        $this->alert($account, ['status' => MonitoringAlert::STATUS_PENDING]);
        $this->alert($account, ['status' => MonitoringAlert::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()]);

        $quota = app(QueryQuotaService::class);
        $quota->reserve($account, $this->monitoringRun($account));
        $quota->reserve($account, $this->monitoringRun($account));

        $older = $this->monitoringRun($account, ['status' => MonitoringRunStatus::Completed]);
        $latest = $this->monitoringRun($account, ['status' => MonitoringRunStatus::Completed]);

        $foreign = $this->createAccount();
        $this->enrollment($foreign);
        $this->alert($foreign);
        $this->monitoringRun($foreign);

        $response = $this->actingAs($actor)->getJson('/api/monitoring/dashboard')->assertOk();

        $response->assertJsonPath('data.associations.active', 2)
            ->assertJsonPath('data.associations.paused', 1)
            ->assertJsonPath('data.associations.ended', 1)
            ->assertJsonPath('data.associations.total', 4)
            ->assertJsonPath('data.alerts.pending', 2)
            ->assertJsonPath('data.alerts.acknowledged', 1)
            ->assertJsonPath('data.last_run.id', $latest->id)
            ->assertJsonPath('data.quota.consumed', 2)
            ->assertJsonPath('data.quota.limit', 10);

        $this->assertNotSame($older->id, $response->json('data.last_run.id'));
        $this->assertSame('active', $active->status);
        $this->assertSame(
            app(QueryQuotaService::class)->usage($account)['period'],
            $response->json('data.quota.period'),
        );
    }

    public function test_dashboard_is_factual_and_empty_for_a_fresh_account(): void
    {
        $plan = Plan::factory()->create(['monthly_query_volume' => 0]);
        $account = $this->createAccount(['plan_id' => $plan->id]);
        $actor = $this->actor($account);

        $response = $this->actingAs($actor)->getJson('/api/monitoring/dashboard')->assertOk();

        $response->assertJsonPath('data.associations.active', 0)
            ->assertJsonPath('data.associations.paused', 0)
            ->assertJsonPath('data.associations.ended', 0)
            ->assertJsonPath('data.associations.total', 0)
            ->assertJsonPath('data.alerts.pending', 0)
            ->assertJsonPath('data.alerts.acknowledged', 0)
            ->assertJsonPath('data.last_run', null)
            ->assertJsonPath('data.quota.limit', 0)
            ->assertJsonPath('data.quota.consumed', 0);
    }

    public function test_dashboard_never_exposes_stored_payloads(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);

        $this->snapshot($account, $enrollment, [
            'data' => ['situacao' => 'PAYLOAD-MARKER-DASHBOARD'],
        ]);
        $this->change($account, $enrollment, [
            'data' => ['before' => ['situacao' => 'PAYLOAD-MARKER-DASHBOARD'], 'after' => []],
        ]);

        $response = $this->actingAs($actor)->getJson('/api/monitoring/dashboard')->assertOk();

        $this->assertStringNotContainsString('PAYLOAD-MARKER-DASHBOARD', $response->getContent());
    }

    public function test_enrollment_snapshots_are_paginated_and_scoped_to_the_enrollment(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);
        $other = $this->enrollment($account);

        $oldest = $this->snapshot($account, $enrollment, [
            'verified_at' => now()->subDays(3),
            'data' => ['situacao' => 'oldest'],
        ]);
        $middle = $this->snapshot($account, $enrollment, [
            'verified_at' => now()->subDay(),
            'data' => ['situacao' => 'middle'],
        ]);
        $newest = $this->snapshot($account, $enrollment, [
            'verified_at' => now(),
            'data' => ['situacao' => 'newest'],
        ]);
        $foreignEnrollment = $this->snapshot($account, $other, ['data' => ['situacao' => 'other']]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$enrollment->id}/snapshots?per_page=2")
            ->assertOk();

        $response->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id);

        $second = $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$enrollment->id}/snapshots?per_page=2&page=2")
            ->assertOk();
        $second->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $oldest->id);

        $ids = collect($response->json('data'))->pluck('id')
            ->merge(collect($second->json('data'))->pluck('id'));
        $this->assertNotContains($foreignEnrollment->id, $ids->all());
    }

    public function test_snapshot_items_expose_state_and_only_normalized_sanitized_data(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);
        $this->monitoringRun($account, [
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
            'status' => MonitoringRunStatus::Completed,
        ]);

        $normalized = $this->snapshot($account, $enrollment, [
            'operation_code' => 'RELATORIOSITFIS92',
            'family' => 'sitfis',
            'normalized' => true,
            'verified_at' => now(),
            'data' => [
                'situacao' => 'regular',
                'nome_arquivo_relatorio' => 'sitfis.pdf',
                'pdf' => 'JVBERi0xLjQKJcOkw7zDtsOf',
                'pdf_storage_ref' => 'secret:artifact-normalized',
                'consumer_secret' => 'top-secret-value',
            ],
        ]);
        $unnormalized = $this->snapshot($account, $enrollment, [
            'operation_code' => 'PAGAMENTOS99',
            'family' => 'pagamentos',
            'normalized' => false,
            'verified_at' => now()->subMinute(),
            'data' => ['raw' => 'UNNORMALIZED-BODY-MARKER'],
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$enrollment->id}/snapshots")
            ->assertOk();

        $response->assertJsonPath('data.0.id', $normalized->id)
            ->assertJsonPath('data.0.normalized', true)
            ->assertJsonPath('data.0.operation_code', 'RELATORIOSITFIS92')
            ->assertJsonPath('data.0.family', 'sitfis')
            ->assertJsonPath('data.0.freshness', MonitoringSnapshot::FRESHNESS_FRESH)
            ->assertJsonPath('data.0.completeness', MonitoringSnapshot::COMPLETENESS_COMPLETE)
            ->assertJsonPath('data.0.data.situacao', 'regular')
            ->assertJsonPath('data.0.data.nome_arquivo_relatorio', 'sitfis.pdf')
            ->assertJsonPath('state.completeness', MonitoringSnapshot::COMPLETENESS_COMPLETE);

        $this->assertNotNull($response->json('data.0.fingerprint'));
        $this->assertNotNull($response->json('data.0.verified_at'));

        $item = $response->json('data.1');
        $this->assertSame($unnormalized->id, $item['id']);
        $this->assertFalse($item['normalized']);
        $this->assertArrayNotHasKey('data', $item);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('storage_ref', $content);
        $this->assertStringNotContainsString('artifact-normalized', $content);
        $this->assertStringNotContainsString('top-secret-value', $content);
        $this->assertStringNotContainsString('JVBERi0xLjQKJcOkw7zDtsOf', $content);
        $this->assertStringNotContainsString('UNNORMALIZED-BODY-MARKER', $content);
    }

    public function test_snapshot_completeness_fails_closed_on_a_blocked_latest_run(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);

        $this->snapshot($account, $enrollment, [
            'operation_code' => 'RELATORIOSITFIS92',
            'data' => ['situacao' => 'regular'],
        ]);
        $this->monitoringRun($account, [
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
            'status' => MonitoringRunStatus::Blocked,
        ]);

        $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$enrollment->id}/snapshots")
            ->assertOk()
            ->assertJsonPath('state.completeness', MonitoringSnapshot::COMPLETENESS_BLOCKED);
    }

    public function test_snapshots_of_another_account_return_404(): void
    {
        $actor = $this->actor($this->createAccount());
        $foreign = $this->enrollment($this->createAccount());

        $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$foreign->id}/snapshots")
            ->assertNotFound();
    }

    public function test_changes_are_paginated_and_expose_normalized_before_and_after_only(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);

        $normalizedSnapshot = $this->snapshot($account, $enrollment, ['normalized' => true]);
        $unnormalizedSnapshot = $this->snapshot($account, $enrollment, [
            'operation_code' => 'PAGAMENTOS99',
            'normalized' => false,
            'data' => ['raw' => 'UNNORMALIZED-CHANGE-MARKER'],
        ]);

        $oldest = $this->change($account, $enrollment, [
            'snapshot_id' => $normalizedSnapshot->id,
            'data' => [
                'before' => ['situacao' => 'regular', 'pdf_storage_ref' => 'secret:old'],
                'after' => ['situacao' => 'irregular'],
            ],
            'created_at' => now()->subDay(),
        ]);
        $newest = $this->change($account, $enrollment, [
            'snapshot_id' => $unnormalizedSnapshot->id,
            'operation_code' => 'PAGAMENTOS99',
            'data' => ['before' => ['raw' => 'UNNORMALIZED-CHANGE-MARKER'], 'after' => []],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$enrollment->id}/changes?per_page=1")
            ->assertOk();

        $response->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.0.kind', MonitoringChange::KIND_CHANGED)
            ->assertJsonPath('data.0.normalized', false);

        $this->assertArrayNotHasKey('data', $response->json('data.0'));

        $second = $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$enrollment->id}/changes?per_page=1&page=2")
            ->assertOk();

        $second->assertJsonPath('data.0.id', $oldest->id)
            ->assertJsonPath('data.0.normalized', true)
            ->assertJsonPath('data.0.data.before.situacao', 'regular')
            ->assertJsonPath('data.0.data.after.situacao', 'irregular');

        $this->assertStringNotContainsString('storage_ref', (string) $second->getContent());
        $this->assertStringNotContainsString('UNNORMALIZED-CHANGE-MARKER', (string) $response->getContent());
    }

    public function test_changes_of_another_account_return_404(): void
    {
        $actor = $this->actor($this->createAccount());
        $foreign = $this->enrollment($this->createAccount());

        $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$foreign->id}/changes")
            ->assertNotFound();
    }

    public function test_runs_listing_is_paginated_filterable_and_keeps_consultation_parameters(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);

        $manual = $this->monitoringRun($account, [
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'CONSDECLARACAO13',
            'parameters' => ['numeroDeclaracao' => '123', 'consumer_secret' => 'hidden'],
            'status' => MonitoringRunStatus::Completed,
        ]);
        $this->monitoringRun($account, [
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
            'trigger' => MonitoringRun::TRIGGER_AUTOMATIC,
            'status' => MonitoringRunStatus::Blocked,
            'error_code' => 'transport_closed',
        ]);
        $foreign = $this->createAccount();
        $this->monitoringRun($foreign, ['enrollment_id' => $this->enrollment($foreign)->id]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/runs?enrollment_id={$enrollment->id}&per_page=1")
            ->assertOk();

        $response->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data');

        $first = $response->json('data.0');
        $this->assertSame('RELATORIOSITFIS92', $first['operation_code']);
        $this->assertSame('automatic', $first['trigger']);
        $this->assertSame('blocked', $first['status']);
        $this->assertSame('transport_closed', $first['error_code']);
        $this->assertArrayHasKey('eta', $first);
        $this->assertArrayHasKey('protocol', $first);
        $this->assertArrayHasKey('created_at', $first);
        $this->assertArrayHasKey('finished_at', $first);

        $byStatus = $this->actingAs($actor)
            ->getJson('/api/monitoring/runs?status=completed')
            ->assertOk();
        $byStatus->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $manual->id)
            ->assertJsonPath('data.0.parameters.numeroDeclaracao', '123');

        $this->assertArrayNotHasKey('consumer_secret', $byStatus->json('data.0.parameters'));

        $byOperation = $this->actingAs($actor)
            ->getJson('/api/monitoring/runs?operation_code=CONSDECLARACAO13')
            ->assertOk();
        $byOperation->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $manual->id);
    }

    public function test_runs_listing_with_a_foreign_enrollment_filter_returns_404(): void
    {
        $actor = $this->actor($this->createAccount());
        $foreign = $this->enrollment($this->createAccount());

        $this->actingAs($actor)
            ->getJson("/api/monitoring/runs?enrollment_id={$foreign->id}")
            ->assertNotFound();
    }

    public function test_alerts_listing_filters_by_status_and_is_scoped_to_the_account(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);

        $pending = $this->alert($account, [
            'enrollment_id' => $enrollment->id,
            'status' => MonitoringAlert::STATUS_PENDING,
        ]);
        $acknowledged = $this->alert($account, [
            'enrollment_id' => $enrollment->id,
            'status' => MonitoringAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);
        $this->alert($this->createAccount());

        $response = $this->actingAs($actor)
            ->getJson('/api/monitoring/alerts?status=pending')
            ->assertOk();

        $response->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', MonitoringAlert::STATUS_PENDING)
            ->assertJsonPath('data.0.enrollment_id', $enrollment->id);

        $all = $this->actingAs($actor)->getJson('/api/monitoring/alerts')->assertOk();
        $all->assertJsonPath('total', 2);

        $byEnrollment = $this->actingAs($actor)
            ->getJson('/api/monitoring/alerts?enrollment_id='.$enrollment->id.'&status=acknowledged')
            ->assertOk();
        $byEnrollment->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $acknowledged->id);
    }

    public function test_acknowledge_is_idempotent_for_an_admin_and_a_user_is_forbidden(): void
    {
        $account = $this->createAccount();
        $enrollment = $this->enrollment($account);
        $alert = $this->alert($account, ['enrollment_id' => $enrollment->id]);

        $admin = $this->actor($account, UserRole::Admin);

        $first = $this->actingAs($admin)
            ->postJson("/api/monitoring/alerts/{$alert->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', MonitoringAlert::STATUS_ACKNOWLEDGED)
            ->assertJsonPath('data.acknowledged_by_user_id', $admin->id);

        $acknowledgedAt = $first->json('data.acknowledged_at');
        $this->assertNotNull($acknowledgedAt);

        $second = $this->actingAs($admin)
            ->postJson("/api/monitoring/alerts/{$alert->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.acknowledged_at', $acknowledgedAt)
            ->assertJsonPath('data.acknowledged_by_user_id', $admin->id);

        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.alert.acknowledged')->count());
        $this->assertSame(1, MonitoringAlert::query()->count());
        $this->assertSame(MonitoringAlert::STATUS_ACKNOWLEDGED, $alert->fresh()->status);

        $user = $this->actor($account, UserRole::User);

        $this->actingAs($user)
            ->postJson("/api/monitoring/alerts/{$alert->id}/acknowledge")
            ->assertForbidden();

        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.alert.acknowledged')->count());
        $this->assertNotNull($second->json('data.acknowledged_at'));
    }

    public function test_acknowledge_of_another_account_returns_404(): void
    {
        $actor = $this->actor($this->createAccount());
        $foreign = $this->alert($this->createAccount());

        $this->actingAs($actor)
            ->postJson("/api/monitoring/alerts/{$foreign->id}/acknowledge")
            ->assertNotFound();

        $this->assertSame(MonitoringAlert::STATUS_PENDING, $foreign->fresh()->status);
    }

    public function test_reads_never_touch_the_transport(): void
    {
        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account);
        $snapshot = $this->snapshot($account, $enrollment, ['data' => ['situacao' => 'regular']]);
        $this->change($account, $enrollment, ['snapshot_id' => $snapshot->id]);
        $this->monitoringRun($account, ['enrollment_id' => $enrollment->id]);
        $alert = $this->alert($account, ['enrollment_id' => $enrollment->id]);

        $this->actingAs($actor)->getJson('/api/monitoring/dashboard')->assertOk();
        $this->actingAs($actor)->getJson("/api/monitoring/enrollments/{$enrollment->id}/snapshots")->assertOk();
        $this->actingAs($actor)->getJson("/api/monitoring/enrollments/{$enrollment->id}/changes")->assertOk();
        $this->actingAs($actor)->getJson('/api/monitoring/runs')->assertOk();
        $this->actingAs($actor)->getJson('/api/monitoring/alerts')->assertOk();
        $this->actingAs($actor)->postJson("/api/monitoring/alerts/{$alert->id}/acknowledge")->assertOk();

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    private function actor(Account $account, UserRole $role = UserRole::Admin): User
    {
        return $this->createUser($account, ['role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrollment(Account $account, ?Client $client = null, ?MonitoringDefinition $definition = null, array $attributes = []): MonitoringEnrollment
    {
        return MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => ($client ?? Client::factory()->for($account, 'account')->create())->id,
            'definition_id' => ($definition ?? MonitoringDefinition::factory()->create())->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function snapshot(Account $account, MonitoringEnrollment $enrollment, array $attributes = []): MonitoringSnapshot
    {
        return MonitoringSnapshot::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $enrollment->client_id,
            'operation_code' => 'RELATORIOSITFIS92',
            'family' => 'sitfis',
            'normalized' => true,
            'fingerprint' => hash('sha256', 'snapshot-'.uniqid()),
            'data' => ['situacao' => 'regular'],
            'freshness' => MonitoringSnapshot::FRESHNESS_FRESH,
            'completeness' => MonitoringSnapshot::COMPLETENESS_COMPLETE,
            'verified_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function change(Account $account, MonitoringEnrollment $enrollment, array $attributes = []): MonitoringChange
    {
        return MonitoringChange::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $enrollment->client_id,
            'operation_code' => 'RELATORIOSITFIS92',
            'kind' => MonitoringChange::KIND_CHANGED,
            'created_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function monitoringRun(Account $account, array $attributes = []): MonitoringRun
    {
        return MonitoringRun::factory()->create([
            'account_id' => $account->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function alert(Account $account, array $attributes = []): MonitoringAlert
    {
        return MonitoringAlert::factory()->create([
            'account_id' => $account->id,
            ...$attributes,
        ]);
    }
}
