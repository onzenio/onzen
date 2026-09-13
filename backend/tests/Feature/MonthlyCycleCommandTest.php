<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Contracts\SerproTransport;
use App\Enums\MonitoringRunStatus;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\Plan;
use App\Models\SerproSettings;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResultProjector;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

/**
 * Automatic monthly cycle (Task 18 / design decision 6): only consult
 * operations of definitions marked automatic, only active associations with
 * an active Client Monitoring Status, trigger `automatic`, quota reserved per
 * run; preview by default, scheduling only with `--confirm`; registered for
 * day 1 at 06:00 America/Sao_Paulo.
 */
final class MonthlyCycleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->app->instance(ResultProjector::class, new FakeResultProjector);
        $this->app->instance(SerproTransport::class, new FakeSerproTransport);
        SerproSettings::current()->update([
            'transport_approved' => false,
            'transport_approved_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_preview_lists_eligible_enrollments_without_creating_runs_or_dispatching(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $this->enrollment($account, $this->automaticDefinition());
        $this->enrollment($account, $this->manualDefinition());

        // The preview only considers the automatic portfolio; the manual
        // definition is out of scope even before eligibility.
        $this->artisan('monitoring:run-monthly-cycle')
            ->expectsOutputToContain('{"dry_run":true,"queued":1,"blocked":0,"skipped":0,"exhausted_accounts":[],"truncated":false}')
            ->assertSuccessful();

        $this->assertDatabaseCount('monitoring_runs', 0);
        $this->assertDatabaseCount('query_quota_consumptions', 0);
        Queue::assertNothingPushed();
    }

    public function test_confirm_schedules_only_automatic_definitions_with_the_automatic_trigger(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $automatic = $this->enrollment($account, $this->automaticDefinition());
        $manual = $this->enrollment($account, $this->manualDefinition());

        $this->artisan('monitoring:run-monthly-cycle --confirm')
            ->expectsOutputToContain('{"dry_run":false,"queued":1,"blocked":0,"skipped":0,"exhausted_accounts":[],"truncated":false}')
            ->assertSuccessful();

        $this->assertDatabaseCount('monitoring_runs', 1);
        $this->assertDatabaseHas('monitoring_runs', [
            'account_id' => $account->id,
            'enrollment_id' => $automatic->id,
            'trigger' => MonitoringRun::TRIGGER_AUTOMATIC,
            'status' => MonitoringRunStatus::Pending->value,
        ]);
        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $manual->id]);
        $this->assertDatabaseHas('query_quota_consumptions', [
            'account_id' => $account->id,
            'trigger' => MonitoringRun::TRIGGER_AUTOMATIC,
        ]);

        Queue::assertPushed(ExecuteSerproJob::class, 1);
    }

    public function test_confirm_is_idempotent_within_the_same_minute(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $this->enrollment($account, $this->automaticDefinition());

        $this->artisan('monitoring:run-monthly-cycle --confirm')->assertSuccessful();
        $this->artisan('monitoring:run-monthly-cycle --confirm')->assertSuccessful();

        $this->assertDatabaseCount('monitoring_runs', 1);
        $this->assertDatabaseCount('query_quota_consumptions', 1);
        Queue::assertPushed(ExecuteSerproJob::class, 1);
    }

    public function test_confirm_skips_associations_outside_the_active_portfolio(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(5);
        $paused = $this->enrollment($account, $this->automaticDefinition(), [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
            'version' => 2,
        ]);
        $disabled = $this->enrollment($account, $this->automaticDefinition(), [
            'client_monitoring_enabled' => false,
        ]);

        // The paused association is out of the active portfolio entirely;
        // the monitoring-off one is filtered in SQL and never considered.
        $this->artisan('monitoring:run-monthly-cycle --confirm')
            ->expectsOutputToContain('{"dry_run":false,"queued":0,"blocked":0,"skipped":0,"exhausted_accounts":[],"truncated":false}')
            ->assertSuccessful();

        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $paused->id]);
        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $disabled->id]);
        $this->assertDatabaseCount('monitoring_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_quota_exhaustion_in_one_account_does_not_stop_the_cycle(): void
    {
        Queue::fake();
        $empty = $this->accountWithVolume(0);
        $funded = $this->accountWithVolume(5);
        $definition = $this->automaticDefinition();

        $blocked = $this->enrollment($empty, $definition);
        $queued = $this->enrollment($funded, $definition);
        $alsoBlocked = $this->enrollment($empty, $definition);

        $expected = (string) json_encode([
            'dry_run' => false,
            'queued' => 1,
            'blocked' => 2,
            'skipped' => 0,
            'exhausted_accounts' => [$empty->id],
            'truncated' => false,
        ], JSON_THROW_ON_ERROR);

        $this->artisan('monitoring:run-monthly-cycle --confirm')
            ->expectsOutputToContain($expected)
            ->assertSuccessful();

        // The exhausted Account is blocked and its remaining rows are not
        // attempted; the funded Account keeps being processed normally.
        $this->assertDatabaseHas('monitoring_runs', [
            'enrollment_id' => $blocked->id,
            'status' => MonitoringRunStatus::Blocked->value,
            'error_code' => 'quota_exceeded',
        ]);
        $this->assertDatabaseHas('monitoring_runs', [
            'enrollment_id' => $queued->id,
            'status' => MonitoringRunStatus::Pending->value,
        ]);
        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $alsoBlocked->id]);

        $this->assertDatabaseCount('monitoring_runs', 2);
        $this->assertDatabaseCount('query_quota_consumptions', 1);
        Queue::assertPushed(ExecuteSerproJob::class, 1);
    }

    public function test_limit_counts_only_eligible_rows_and_signals_truncation(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(10);
        $definition = $this->automaticDefinition();

        $ineligible = $this->enrollment($account, $definition, ['client_monitoring_enabled' => false]);
        $first = $this->enrollment($account, $definition);
        $second = $this->enrollment($account, $definition);
        $third = $this->enrollment($account, $definition);

        // The Monitoring-Status-off row is filtered in SQL and must not
        // consume the cap; three eligible rows with limit 2 truncate.
        $this->artisan('monitoring:run-monthly-cycle --confirm --limit=2')
            ->expectsOutputToContain('{"dry_run":false,"queued":2,"blocked":0,"skipped":0,"exhausted_accounts":[],"truncated":true}')
            ->assertSuccessful();

        $this->assertDatabaseHas('monitoring_runs', ['enrollment_id' => $first->id]);
        $this->assertDatabaseHas('monitoring_runs', ['enrollment_id' => $second->id]);
        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $third->id]);
        $this->assertDatabaseMissing('monitoring_runs', ['enrollment_id' => $ineligible->id]);
        $this->assertDatabaseCount('monitoring_runs', 2);

        $this->travel(61)->seconds();

        // With room for every eligible row the run reports no truncation.
        $this->artisan('monitoring:run-monthly-cycle --confirm --limit=5')
            ->expectsOutputToContain('{"dry_run":false,"queued":3,"blocked":0,"skipped":0,"exhausted_accounts":[],"truncated":false}')
            ->assertSuccessful();

        $this->assertDatabaseCount('monitoring_runs', 5);
    }

    public function test_seeded_metadata_marks_the_three_legacy_definitions_as_automatic(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        foreach (['pgdas-declaracoes', 'dctfweb', 'situacao-fiscal'] as $id) {
            $this->assertDatabaseHas('monitoring_definitions', [
                'id' => $id,
                'strategy' => MonitoringDefinition::STRATEGY_AUTOMATIC,
            ]);
        }

        $this->assertDatabaseHas('monitoring_definitions', [
            'id' => 'regime-apuracao',
            'strategy' => MonitoringDefinition::STRATEGY_POLLING,
        ]);
    }

    public function test_the_cycle_is_scheduled_for_day_one_at_six_sao_paulo(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events());

        $event = $events->first(
            fn ($event): bool => str_contains((string) $event->command, 'monitoring:run-monthly-cycle'),
        );

        $this->assertNotNull($event, 'The monthly cycle must be registered in the scheduler.');
        $this->assertStringContainsString('--confirm', (string) $event->command);
        $this->assertSame('0 6 1 * *', $event->expression);
        $this->assertSame('America/Sao_Paulo', $event->timezone);
    }

    public function test_automatic_runs_reserve_the_plan_quota_before_dispatch(): void
    {
        Queue::fake();
        $account = $this->accountWithVolume(2);
        $enrollment = $this->enrollment($account, $this->automaticDefinition());

        $this->artisan('monitoring:run-monthly-cycle --confirm')->assertSuccessful();

        $run = MonitoringRun::query()->firstOrFail();
        $this->assertSame($enrollment->id, $run->enrollment_id);
        $this->assertSame(MonitoringRun::TRIGGER_AUTOMATIC, $run->trigger);
        $this->assertDatabaseHas('query_quota_consumptions', [
            'run_id' => $run->id,
            'trigger' => MonitoringRun::TRIGGER_AUTOMATIC,
        ]);
    }

    private function accountWithVolume(int $volume): Account
    {
        $plan = Plan::factory()->create(['monthly_query_volume' => $volume]);

        return $this->createAccount(['plan_id' => $plan->id]);
    }

    private function automaticDefinition(): MonitoringDefinition
    {
        return MonitoringDefinition::factory()->create([
            'id' => 'pgdas-declaracoes-'.uniqid(),
            'operations' => ['CONSDECLARACAO13'],
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
            'strategy' => MonitoringDefinition::STRATEGY_AUTOMATIC,
        ]);
    }

    private function manualDefinition(): MonitoringDefinition
    {
        return MonitoringDefinition::factory()->create([
            'id' => 'regime-apuracao-'.uniqid(),
            'operations' => ['CONSULTARANOSCALENDARIOS102'],
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
            'strategy' => MonitoringDefinition::STRATEGY_POLLING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrollment(Account $account, MonitoringDefinition $definition, array $attributes = []): MonitoringEnrollment
    {
        $monitoringEnabled = (bool) ($attributes['client_monitoring_enabled'] ?? true);
        unset($attributes['client_monitoring_enabled']);

        $client = Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => $monitoringEnabled,
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
