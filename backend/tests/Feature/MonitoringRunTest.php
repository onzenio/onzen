<?php

namespace Tests\Feature;

use App\Enums\MonitoringRunStatus;
use App\Exceptions\InvalidRunTransitionException;
use App\Models\MonitoringAttempt;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Support\CurrentAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonitoringRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_runs', [
            'id', 'account_id', 'enrollment_id', 'trigger', 'definition_key',
            'operation_code', 'idempotency_key', 'fencing_token', 'status',
            'environment', 'dry_run', 'protocol', 'eta', 'parameters',
            'external_code', 'error_code', 'started_at', 'finished_at',
            'created_at', 'updated_at',
        ]));
    }

    public function test_attempts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_attempts', [
            'id', 'run_id', 'attempt', 'status', 'response_code',
            'classification', 'retry_after', 'created_at', 'updated_at',
        ]));
    }

    public function test_a_run_belongs_to_an_enrollment_and_has_many_attempts(): void
    {
        $enrollment = MonitoringEnrollment::factory()->create();
        CurrentAccount::set($enrollment->account_id);
        $run = MonitoringRun::factory()->create([
            'account_id' => $enrollment->account_id,
            'enrollment_id' => $enrollment->id,
        ]);

        $attempt = $run->attempts()->create([
            'attempt' => 1,
            'status' => MonitoringRunStatus::Completed,
            'response_code' => 200,
            'classification' => 'ok',
        ]);

        $this->assertTrue($run->enrollment->is($enrollment));
        $this->assertCount(1, $run->fresh()->attempts);
        $this->assertTrue($attempt->run->is($run));
        $this->assertSame(MonitoringRunStatus::Completed, $attempt->fresh()->status);
    }

    public function test_the_account_global_scope_filters_runs(): void
    {
        $accountA = $this->createAccount();
        $accountB = $this->createAccount();

        $runA = MonitoringRun::factory()->create(['account_id' => $accountA->id]);
        MonitoringRun::factory()->create(['account_id' => $accountB->id]);

        CurrentAccount::set($accountA->id);

        $visible = MonitoringRun::query()->get();

        $this->assertCount(1, $visible);
        $this->assertTrue($visible->first()->is($runA));
    }

    public function test_the_happy_path_transitions_and_stamps_the_run(): void
    {
        $run = MonitoringRun::factory()->create();
        $this->assertSame(MonitoringRunStatus::Pending, $run->status);
        $this->assertNull($run->started_at);

        $running = $run->transitionTo(MonitoringRunStatus::Running);
        $this->assertSame(MonitoringRunStatus::Running, $running->status);
        $this->assertNotNull($running->started_at);
        $this->assertNull($running->finished_at);

        $completed = $running->transitionTo(MonitoringRunStatus::Completed);
        $this->assertSame(MonitoringRunStatus::Completed, $completed->status);
        $this->assertNotNull($completed->finished_at);
        $this->assertSame(MonitoringRunStatus::Completed, $run->fresh()->status);
    }

    public function test_protocol_transition_persists_protocol_and_eta(): void
    {
        $run = MonitoringRun::factory()->create();
        $run->transitionTo(MonitoringRunStatus::Running);
        $eta = now()->addMinutes(10);

        $waiting = $run->transitionTo(MonitoringRunStatus::AwaitingProtocol, [
            'protocol' => 'PROTO-1',
            'eta' => $eta,
        ]);

        $flat = $waiting->fresh();

        $this->assertSame(MonitoringRunStatus::AwaitingProtocol, $flat->status);
        $this->assertSame('PROTO-1', $flat->protocol);
        $this->assertNotNull($flat->eta);
        $this->assertSame($eta->format('Y-m-d H:i:s'), $flat->eta->format('Y-m-d H:i:s'));
        $this->assertNull($flat->finished_at);
    }

    public function test_retryable_transitions_are_wired_and_do_not_finish_the_run(): void
    {
        $run = MonitoringRun::factory()->create();
        $run->transitionTo(MonitoringRunStatus::Running);

        $limited = $run->transitionTo(MonitoringRunStatus::Limited, ['error_code' => 'rate_limited']);
        $this->assertSame(MonitoringRunStatus::Limited, $limited->status);
        $this->assertNull($limited->finished_at);

        $retried = $limited->transitionTo(MonitoringRunStatus::Running);
        $this->assertSame(MonitoringRunStatus::Running, $retried->status);
        $this->assertNull($retried->finished_at);

        $transient = $retried->transitionTo(MonitoringRunStatus::Transient, ['error_code' => 'transport_error']);
        $this->assertSame(MonitoringRunStatus::Transient, $transient->status);
        $this->assertNull($transient->finished_at);
    }

    public function test_an_invalid_transition_is_refused_with_a_factual_error(): void
    {
        $run = MonitoringRun::factory()->create();

        try {
            $run->transitionTo(MonitoringRunStatus::Completed);
            $this->fail('A pending run must not jump straight to completed.');
        } catch (InvalidRunTransitionException $exception) {
            $this->assertSame('invalid_run_transition:pending->completed', $exception->getMessage());
        }

        $this->assertSame(MonitoringRunStatus::Pending, $run->fresh()->status);
    }

    public function test_terminal_states_refuse_any_transition(): void
    {
        $terminal = [
            MonitoringRunStatus::Completed,
            MonitoringRunStatus::Rejected,
            MonitoringRunStatus::Expired,
            MonitoringRunStatus::Failed,
            MonitoringRunStatus::Blocked,
            MonitoringRunStatus::Discarded,
        ];

        foreach ($terminal as $status) {
            $run = MonitoringRun::factory()->create([
                'status' => $status,
                'finished_at' => now(),
            ]);

            $this->assertTrue($status->isTerminal(), $status->value);

            try {
                $run->transitionTo(MonitoringRunStatus::Running);
                $this->fail("Terminal status {$status->value} must refuse a transition.");
            } catch (InvalidRunTransitionException $exception) {
                $this->assertSame("invalid_run_transition:{$status->value}->running", $exception->getMessage());
            }

            $this->assertSame($status, $run->fresh()->status);
        }
    }

    public function test_non_terminal_states_are_exposed(): void
    {
        $nonTerminal = [
            MonitoringRunStatus::Pending,
            MonitoringRunStatus::Running,
            MonitoringRunStatus::AwaitingProtocol,
            MonitoringRunStatus::Limited,
            MonitoringRunStatus::Transient,
        ];

        foreach ($nonTerminal as $status) {
            $this->assertFalse($status->isTerminal(), $status->value);
        }
    }

    public function test_discarded_is_reachable_from_a_pending_run_and_records_the_outcome(): void
    {
        $run = MonitoringRun::factory()->create();

        $discarded = $run->transitionTo(MonitoringRunStatus::Discarded, ['error_code' => 'superseded']);

        $this->assertSame(MonitoringRunStatus::Discarded, $discarded->status);
        $this->assertSame('superseded', $discarded->error_code);
        $this->assertNotNull($discarded->finished_at);
    }

    public function test_attempts_are_unique_per_run(): void
    {
        $run = MonitoringRun::factory()->create();
        $run->attempts()->create(['attempt' => 1, 'status' => MonitoringRunStatus::Running]);

        $this->expectException(UniqueConstraintViolationException::class);

        $run->attempts()->create(['attempt' => 1, 'status' => MonitoringRunStatus::Completed]);
    }

    public function test_attempt_values_round_trip(): void
    {
        $run = MonitoringRun::factory()->create();
        $attempt = MonitoringAttempt::factory()->create([
            'run_id' => $run->id,
            'attempt' => 2,
            'status' => MonitoringRunStatus::Transient,
            'response_code' => 503,
            'classification' => 'transient_error',
            'retry_after' => 60,
        ]);

        $flat = $attempt->fresh();

        $this->assertSame(MonitoringRunStatus::Transient, $flat->status);
        $this->assertSame(503, $flat->response_code);
        $this->assertSame('transient_error', $flat->classification);
        $this->assertSame(60, $flat->retry_after);
    }

    public function test_factory_defaults_are_pending_and_fail_closed(): void
    {
        $run = MonitoringRun::factory()->create();

        $this->assertSame(MonitoringRunStatus::Pending, $run->status);
        $this->assertTrue($run->dry_run);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame('homologacao', $run->environment);
        $this->assertNotNull($run->idempotency_key);
    }
}
