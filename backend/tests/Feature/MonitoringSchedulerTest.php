<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Contracts\SerproTransport;
use App\Enums\MonitoringRunStatus;
use App\Enums\UserRole;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResultProjector;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

/**
 * Manual and batch triggers (Task 18 / spec "Disparo manual e ciclo
 * automático"): 202 with the run id, no external traffic in the HTTP
 * request, quota reserved before dispatch, idempotency by
 * enrollment+fencing+trigger+minute and fail-closed refusals by Client
 * Monitoring Status and association status.
 */
final class MonitoringSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private FakeSerproTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->app->instance(ResultProjector::class, new FakeResultProjector);

        $this->transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_manual_run_returns_202_with_the_run_id_and_dispatches_without_external_calls(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Operator]);
        $enrollment = $this->enrollment($account);

        $response = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run");

        $response->assertAccepted()
            ->assertJsonPath('data.trigger', MonitoringRun::TRIGGER_MANUAL)
            ->assertJsonPath('data.enrollment_id', $enrollment->id)
            ->assertJsonPath('data.status', MonitoringRunStatus::Pending->value);

        $runId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $runId);

        $this->assertDatabaseHas('monitoring_runs', [
            'id' => $runId,
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'trigger' => MonitoringRun::TRIGGER_MANUAL,
            'status' => MonitoringRunStatus::Pending->value,
            'fencing_token' => 1,
        ]);

        $this->assertDatabaseHas('query_quota_consumptions', [
            'account_id' => $account->id,
            'run_id' => $runId,
            'trigger' => MonitoringRun::TRIGGER_MANUAL,
        ]);

        Queue::assertPushed(
            ExecuteSerproJob::class,
            fn (ExecuteSerproJob $job): bool => $job->runId === $runId,
        );

        // The request never touches the wire: the executor only runs in the
        // queue worker.
        $this->assertCount(0, $this->transport->tokenCalls);
        $this->assertCount(0, $this->transport->callCalls);
    }

    public function test_manual_run_accepts_a_definition_outside_the_automatic_cycle(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account, [
            'definition' => MonitoringDefinition::factory()->create([
                'operations' => ['CONSDECLARACAO13'],
                'strategy' => MonitoringDefinition::STRATEGY_POLLING,
            ]),
        ]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertAccepted();

        $this->assertDatabaseCount('monitoring_runs', 1);
    }

    public function test_double_click_within_the_same_minute_returns_a_single_run(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account);

        $first = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertAccepted();

        $second = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertAccepted();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('monitoring_runs', 1);
        $this->assertDatabaseCount('query_quota_consumptions', 1);
        Queue::assertPushed(ExecuteSerproJob::class, 1);
    }

    public function test_a_new_minute_mints_a_new_idempotency_key(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertAccepted();

        $this->travel(61)->seconds();

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertAccepted();

        $this->assertDatabaseCount('monitoring_runs', 2);
        $this->assertDatabaseCount('query_quota_consumptions', 2);
    }

    public function test_user_role_cannot_trigger_or_sync(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $user = $this->createUser($account, ['role' => UserRole::User]);
        $enrollment = $this->enrollment($account);

        $this->actingAs($user)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/monitoring/sync')
            ->assertForbidden();

        $this->assertDatabaseCount('monitoring_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_client_with_monitoring_status_off_is_refused_factually(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account, ['client_monitoring_enabled' => false]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertStatus(409)
            ->assertJsonPath('error', 'monitoring_disabled');

        $this->assertDatabaseCount('monitoring_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_paused_association_is_refused_factually(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
            'version' => 2,
        ]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertStatus(409)
            ->assertJsonPath('error', 'enrollment_inactive');

        $this->assertDatabaseCount('monitoring_runs', 0);
    }

    public function test_ended_association_is_refused_factually(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account, [
            'status' => MonitoringEnrollment::STATUS_ENDED,
            'version' => 2,
        ]);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertStatus(409)
            ->assertJsonPath('error', 'enrollment_inactive');

        $this->assertDatabaseCount('monitoring_runs', 0);
    }

    public function test_cross_account_association_is_an_indistinguishable_404(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $foreign = $this->enrollment($this->accountWithVolume(5));

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$foreign->id}/run")
            ->assertNotFound();

        $this->assertDatabaseCount('monitoring_runs', 0);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $enrollment = $this->enrollment($this->accountWithVolume(5));

        $this->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")->assertUnauthorized();
        $this->postJson('/api/monitoring/sync')->assertUnauthorized();
    }

    public function test_quota_exhausted_is_a_422_without_dispatch_and_the_run_is_factually_blocked(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(0);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account);

        $response = $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('quota');
        $this->assertStringContainsString('upgrade', mb_strtolower((string) $response->json('message')));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('query_quota_consumptions', 0);

        $run = MonitoringRun::query()->firstOrFail();
        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('quota_exceeded', $run->error_code);
        $this->assertTrue($run->isTerminal());
    }

    public function test_quota_exhausted_replay_stays_a_422_and_never_dispatches(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(0);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $enrollment = $this->enrollment($account);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertStatus(422);

        $this->actingAs($actor)
            ->postJson("/api/monitoring/enrollments/{$enrollment->id}/run")
            ->assertStatus(422);

        $this->assertDatabaseCount('monitoring_runs', 1);
        $this->assertDatabaseCount('query_quota_consumptions', 0);
        Queue::assertNothingPushed();
    }

    public function test_sync_queues_the_eligible_portfolio_and_counts_skips(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(10);
        $actor = $this->createUser($account, ['role' => UserRole::Operator]);

        $first = $this->enrollment($account);
        $second = $this->enrollment($account, [
            'definition' => MonitoringDefinition::factory()->create([
                'operations' => ['CONSRECIBO32'],
                'person_types' => ['PF', 'PJ'],
                'regimes' => null,
            ]),
        ]);
        $skipped = $this->enrollment($account, ['client_monitoring_enabled' => false]);

        $response = $this->actingAs($actor)->postJson('/api/monitoring/sync');

        $response->assertOk()->assertJsonPath('data', [
            'queued' => 2,
            'blocked' => 0,
            'skipped' => 1,
        ]);

        $this->assertDatabaseHas('monitoring_runs', ['enrollment_id' => $first->id]);
        $this->assertDatabaseHas('monitoring_runs', ['enrollment_id' => $second->id]);
        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $skipped->id]);
        Queue::assertPushed(ExecuteSerproJob::class, 2);
    }

    public function test_sync_filters_by_client_and_definition(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(10);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);

        $first = $this->enrollment($account);
        $secondDefinition = MonitoringDefinition::factory()->create([
            'operations' => ['CONSRECIBO32'],
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
        ]);
        $second = $this->enrollment($account, ['definition' => $secondDefinition]);

        $this->actingAs($actor)
            ->postJson('/api/monitoring/sync', ['client_id' => $first->client_id])
            ->assertOk()
            ->assertJsonPath('data.queued', 1);

        $this->assertDatabaseHas('monitoring_runs', ['enrollment_id' => $first->id]);
        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $second->id]);

        $this->travel(61)->seconds();

        $this->actingAs($actor)
            ->postJson('/api/monitoring/sync', ['definition_id' => $secondDefinition->id])
            ->assertOk()
            ->assertJsonPath('data.queued', 1);

        $this->assertDatabaseHas('monitoring_runs', ['enrollment_id' => $second->id]);
    }

    public function test_sync_stops_cleanly_on_quota_exhaustion(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(1);
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->enrollment($account);
        $this->enrollment($account);
        $this->enrollment($account);

        $response = $this->actingAs($actor)->postJson('/api/monitoring/sync');

        $response->assertOk()->assertJsonPath('data', [
            'queued' => 1,
            'blocked' => 1,
            'skipped' => 1,
        ]);

        $this->assertDatabaseCount('monitoring_runs', 2);
        $this->assertDatabaseCount('query_quota_consumptions', 1);
        Queue::assertPushed(ExecuteSerproJob::class, 1);
    }

    private function accountWithVolume(int $volume): Account
    {
        $plan = Plan::factory()->create(['monthly_query_volume' => $volume]);

        return $this->createAccount(['plan_id' => $plan->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrollment(Account $account, array $attributes = []): MonitoringEnrollment
    {
        $monitoringEnabled = (bool) ($attributes['client_monitoring_enabled'] ?? true);
        unset($attributes['client_monitoring_enabled']);

        /** @var MonitoringDefinition|null $definition */
        $definition = $attributes['definition'] ?? null;
        unset($attributes['definition']);

        $client = Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => $monitoringEnabled,
        ]);

        $definition ??= MonitoringDefinition::factory()->create([
            'operations' => ['CONSDECLARACAO13'],
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
        ]);

        return MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
            'last_change_at' => now(),
            ...$attributes,
        ]);
    }
}
