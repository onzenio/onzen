<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Enums\MonitoringRunStatus;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Services\Monitoring\SnapshotProjector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SnapshotProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_result_projector_contract_resolves_to_the_snapshot_projector(): void
    {
        $this->assertInstanceOf(SnapshotProjector::class, app(ResultProjector::class));
    }

    public function test_snapshots_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_snapshots', [
            'id', 'account_id', 'enrollment_id', 'client_id', 'run_id',
            'operation_code', 'family', 'normalized', 'fingerprint', 'data',
            'freshness', 'completeness', 'verified_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_changes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_changes', [
            'id', 'account_id', 'enrollment_id', 'client_id', 'snapshot_id',
            'previous_snapshot_id', 'run_id', 'operation_code', 'kind', 'data', 'created_at',
        ]));
    }

    public function test_first_successful_result_publishes_one_complete_snapshot(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $run = $this->runFor($enrollment, 'RELATORIOSITFIS92');

        $this->projector()->project($run, $this->sitfisResult('regular'));

        $snapshot = MonitoringSnapshot::query()->sole();
        $this->assertSame($account->id, $snapshot->account_id);
        $this->assertSame($enrollment->id, $snapshot->enrollment_id);
        $this->assertSame($client->id, $snapshot->client_id);
        $this->assertSame($run->id, $snapshot->run_id);
        $this->assertSame('RELATORIOSITFIS92', $snapshot->operation_code);
        $this->assertSame('sitfis', $snapshot->family);
        $this->assertTrue($snapshot->normalized);
        $this->assertSame(MonitoringSnapshot::COMPLETENESS_COMPLETE, $snapshot->completeness);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_FRESH, $snapshot->freshness);
        $this->assertNotNull($snapshot->verified_at);
        $this->assertSame('regular', $snapshot->data['situacao']);
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_repeating_the_same_result_updates_verification_without_versioning(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();

        $projector->project($this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-1'), $this->sitfisResult('regular'));
        $snapshot = MonitoringSnapshot::query()->sole();
        $firstVerifiedAt = $snapshot->verified_at;
        $fingerprint = $snapshot->fingerprint;

        $this->travel(5)->minutes();

        $projector->project($this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-2'), $this->sitfisResult('regular'));

        $this->assertSame(1, MonitoringSnapshot::query()->count());
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());

        $snapshot = MonitoringSnapshot::query()->sole();
        $this->assertSame($fingerprint, $snapshot->fingerprint);
        $this->assertTrue($snapshot->verified_at->greaterThan($firstVerifiedAt));
        $this->assertSame(MonitoringSnapshot::FRESHNESS_FRESH, $snapshot->freshness);
    }

    public function test_changed_result_publishes_new_snapshot_change_and_exactly_one_alert(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();
        $firstRun = $this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-1');
        $secondRun = $this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-2');

        $projector->project($firstRun, $this->sitfisResult('regular'));
        $projector->project($secondRun, $this->sitfisResult('irregular'));

        $snapshots = MonitoringSnapshot::query()->orderBy('id')->get();
        $this->assertCount(2, $snapshots);
        [$previous, $current] = [$snapshots[0], $snapshots[1]];

        $change = MonitoringChange::query()->sole();
        $this->assertSame($current->id, $change->snapshot_id);
        $this->assertSame($previous->id, $change->previous_snapshot_id);
        $this->assertSame($secondRun->id, $change->run_id);
        $this->assertSame('RELATORIOSITFIS92', $change->operation_code);
        $this->assertSame(MonitoringChange::KIND_CHANGED, $change->kind);
        $this->assertSame('regular', $change->data['before']['situacao']);
        $this->assertSame('irregular', $change->data['after']['situacao']);

        $alert = MonitoringAlert::query()->sole();
        $this->assertSame($account->id, $alert->account_id);
        $this->assertSame($enrollment->id, $alert->enrollment_id);
        $this->assertSame($client->id, $alert->client_id);
        $this->assertSame($change->id, $alert->change_id);
        $this->assertSame(MonitoringAlert::STATUS_PENDING, $alert->status);
        $this->assertNull($alert->acknowledged_at);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_STALE, $previous->fresh()->freshness);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_FRESH, $current->fresh()->freshness);

        // Re-delivering the same result never duplicates the version, change or alert.
        $projector->project($secondRun, $this->sitfisResult('irregular'));

        $this->assertSame(2, MonitoringSnapshot::query()->count());
        $this->assertSame(1, MonitoringChange::query()->count());
        $this->assertSame(1, MonitoringAlert::query()->count());
    }

    public function test_a_reversion_to_a_previous_result_publishes_a_new_version(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();
        $firstRun = $this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-1');
        $secondRun = $this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-2');
        $thirdRun = $this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-3');

        $projector->project($firstRun, $this->sitfisResult('regular'));
        $projector->project($secondRun, $this->sitfisResult('irregular'));
        $projector->project($thirdRun, $this->sitfisResult('regular'));

        $snapshots = MonitoringSnapshot::query()->orderBy('id')->get();
        $this->assertCount(3, $snapshots);
        [$first, $second, $third] = [$snapshots[0], $snapshots[1], $snapshots[2]];

        // The reversion is a factual new version: the same fingerprint as the
        // first publication, but a distinct row published by the third run.
        $this->assertSame($first->fingerprint, $third->fingerprint);
        $this->assertSame($thirdRun->id, $third->run_id);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_STALE, $second->fresh()->freshness);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_FRESH, $third->fresh()->freshness);

        $changes = MonitoringChange::query()->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $reversion = $changes[1];
        $this->assertSame($third->id, $reversion->snapshot_id);
        $this->assertSame($second->id, $reversion->previous_snapshot_id);
        $this->assertSame($thirdRun->id, $reversion->run_id);
        $this->assertSame('irregular', $reversion->data['before']['situacao']);
        $this->assertSame('regular', $reversion->data['after']['situacao']);
        $this->assertSame(2, MonitoringAlert::query()->count());
    }

    public function test_projecting_the_same_run_twice_is_idempotent(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();
        $run = $this->runFor($enrollment, 'RELATORIOSITFIS92');

        $projector->project($run, $this->sitfisResult('regular'));
        $projector->project($run, $this->sitfisResult('irregular'));

        $this->assertSame(1, MonitoringSnapshot::query()->count());
        $this->assertSame('regular', MonitoringSnapshot::query()->sole()->data['situacao']);
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_a_second_version_cannot_be_created_for_the_same_run(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $run = $this->runFor($enrollment, 'RELATORIOSITFIS92');
        $this->projector()->project($run, $this->sitfisResult('regular'));

        $this->expectException(UniqueConstraintViolationException::class);

        MonitoringSnapshot::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $client->id,
            'run_id' => $run->id,
        ]);
    }

    public function test_fingerprints_are_keyed_by_operation_code_for_the_same_enrollment(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();

        $projector->project($this->runFor($enrollment, 'CONSDECLARACAO13', 'index-run'), [
            'source' => 'fixture',
            'operation_code' => 'CONSDECLARACAO13',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => [
                'anoCalendario' => '2026',
                'declaracoes' => [['periodoApuracao' => '202601', 'numeroDeclaracao' => '13']],
            ]],
        ]);

        $projector->project($this->runFor($enrollment, 'GERARDAS12', 'das-run'), [
            'source' => 'fixture',
            'operation_code' => 'GERARDAS12',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => [['periodoApuracao' => '202601', 'pdf' => 'JVBERi0x']]],
        ]);

        $this->assertSame(2, MonitoringSnapshot::query()->count());
        $this->assertEqualsCanonicalizing(
            ['CONSDECLARACAO13', 'GERARDAS12'],
            MonitoringSnapshot::query()->pluck('operation_code')->all(),
        );
        // The carry-over ruling: GERARDAS12 is keyed under the pgdasd family.
        $this->assertSame('pgdasd', MonitoringSnapshot::query()->where('operation_code', 'GERARDAS12')->sole()->family);
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_a_non_normalized_result_is_stored_flagged_and_fingerprints_stably(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();
        $raw = ['dados' => [
            'pagamentos' => [['competencia' => '202601', 'valor' => '150.00']],
        ]];

        $projector->project($this->runFor($enrollment, 'PAGAMENTOS71', 'run-1'), [
            'source' => 'fixture',
            'operation_code' => 'PAGAMENTOS71',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => $raw,
        ]);

        $snapshot = MonitoringSnapshot::query()->sole();
        $this->assertFalse($snapshot->normalized);
        $this->assertSame('pagamentos', $snapshot->family);
        $this->assertSame($raw['dados'], $snapshot->data);
        $this->assertSame(MonitoringSnapshot::COMPLETENESS_COMPLETE, $snapshot->completeness);

        $projector->project($this->runFor($enrollment, 'PAGAMENTOS71', 'run-2'), [
            'source' => 'serpro',
            'operation_code' => 'PAGAMENTOS71',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => $raw,
        ]);

        $this->assertSame(1, MonitoringSnapshot::query()->count());
        $this->assertSame($snapshot->fingerprint, MonitoringSnapshot::query()->sole()->fingerprint);
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_an_awaiting_protocol_result_does_not_publish(): void
    {
        [$account, $client, $enrollment] = $this->context();

        $this->projector()->project($this->runFor($enrollment, 'SOLICITARPROTOCOLO91'), [
            'source' => 'fixture',
            'operation_code' => 'SOLICITARPROTOCOLO91',
            'http_status' => 202,
            'protocol' => 'SITFIS-PROTO-91',
            'eta' => null,
            'body' => [
                'protocol' => ['protocol_id' => 'SITFIS-PROTO-91', 'obtained' => false],
                'dados' => [],
            ],
        ]);

        $this->assertSame(0, MonitoringSnapshot::query()->count());
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_a_blocked_result_does_not_publish(): void
    {
        [$account, $client, $enrollment] = $this->context();

        $this->projector()->project($this->runFor($enrollment, 'RELATORIOSITFIS92'), [
            'source' => 'serpro',
            'operation_code' => 'RELATORIOSITFIS92',
            'http_status' => 403,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => []],
        ]);

        $this->assertSame(0, MonitoringSnapshot::query()->count());
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_an_expired_protocol_marks_the_current_snapshot_stale_without_publishing(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();
        $projector->project($this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-1'), $this->sitfisResult('regular'));

        $projector->project($this->runFor($enrollment, 'RELATORIOSITFIS92', 'run-2'), [
            'source' => 'serpro',
            'operation_code' => 'RELATORIOSITFIS92',
            'http_status' => 200,
            'protocol' => 'SITFIS-PROTO-91',
            'eta' => null,
            'body' => ['expired' => true, 'dados' => []],
        ]);

        $snapshot = MonitoringSnapshot::query()->sole();
        $this->assertSame(MonitoringSnapshot::FRESHNESS_STALE, $snapshot->freshness);
        $this->assertSame('regular', $snapshot->data['situacao']);
        $this->assertSame(MonitoringSnapshot::COMPLETENESS_COMPLETE, $snapshot->completeness);
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_a_result_without_an_operation_code_never_publishes(): void
    {
        [$account, $client, $enrollment] = $this->context();

        $this->projector()->project($this->runFor($enrollment, ''), [
            'source' => 'fixture',
            'operation_code' => '',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => ['dados' => ['situacao' => 'regular']],
        ]);

        $this->assertSame(0, MonitoringSnapshot::query()->count());
        $this->assertSame(0, MonitoringChange::query()->count());
        $this->assertSame(0, MonitoringAlert::query()->count());
    }

    public function test_state_for_exposes_freshness_completeness_and_coverage(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();
        $run = $this->runFor($enrollment, 'RELATORIOSITFIS92');
        $projector->project($run, $this->sitfisResult('regular'));
        $run->transitionTo(MonitoringRunStatus::Completed);

        $state = $projector->stateFor($enrollment);

        $this->assertSame(MonitoringSnapshot::FRESHNESS_FRESH, $state['freshness']);
        $this->assertSame(MonitoringSnapshot::COMPLETENESS_COMPLETE, $state['completeness']);
        $this->assertSame(['RELATORIOSITFIS92'], $state['coverage']['operations']);
        $this->assertSame(['sitfis'], $state['coverage']['families']);
        $this->assertNotNull($state['verified_at']);
    }

    public function test_state_for_reports_incomplete_while_a_protocol_awaits(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $projector = $this->projector();
        $projector->project($this->runFor($enrollment, 'RELATORIOSITFIS92'), $this->sitfisResult('regular'));

        MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
            'status' => MonitoringRunStatus::AwaitingProtocol,
            'protocol' => 'SITFIS-PROTO-91',
        ]);

        $state = $projector->stateFor($enrollment);

        $this->assertSame(MonitoringSnapshot::COMPLETENESS_INCOMPLETE, $state['completeness']);
        // The published snapshot stays the last complete version.
        $snapshot = MonitoringSnapshot::query()->sole();
        $this->assertSame(MonitoringSnapshot::COMPLETENESS_COMPLETE, $snapshot->completeness);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_FRESH, $state['freshness']);
    }

    public function test_state_for_reports_blocked_without_any_snapshot(): void
    {
        [$account, $client, $enrollment] = $this->context();

        MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
            'status' => MonitoringRunStatus::Blocked,
            'error_code' => 'quota_exceeded',
        ]);

        $state = $this->projector()->stateFor($enrollment);

        $this->assertSame(MonitoringSnapshot::COMPLETENESS_BLOCKED, $state['completeness']);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_STALE, $state['freshness']);
        $this->assertSame([], $state['coverage']['operations']);
        $this->assertSame(0, MonitoringSnapshot::query()->count());
    }

    public function test_state_for_reports_incomplete_for_a_completed_run_without_a_snapshot(): void
    {
        [$account, $client, $enrollment] = $this->context();

        MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
            'status' => MonitoringRunStatus::Completed,
        ]);

        $state = $this->projector()->stateFor($enrollment);

        // Fail-closed read contract: a completed run without a published
        // snapshot is never reported as complete.
        $this->assertSame(MonitoringSnapshot::COMPLETENESS_INCOMPLETE, $state['completeness']);
        $this->assertSame(MonitoringSnapshot::FRESHNESS_STALE, $state['freshness']);
        $this->assertSame([], $state['coverage']['operations']);
        $this->assertSame(0, MonitoringSnapshot::query()->count());
    }

    private function projector(): SnapshotProjector
    {
        return app(SnapshotProjector::class);
    }

    /**
     * @return array{0: Account, 1: Client, 2: MonitoringEnrollment}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create();
        $definition = MonitoringDefinition::factory()->create();
        $enrollment = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ]);

        return [$account, $client, $enrollment];
    }

    private function runFor(MonitoringEnrollment $enrollment, string $operationCode, ?string $key = null): MonitoringRun
    {
        return MonitoringRun::factory()->create([
            'account_id' => $enrollment->account_id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => $operationCode,
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'status' => MonitoringRunStatus::Running,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sitfisResult(string $situacao): array
    {
        return [
            'source' => 'fixture',
            'operation_code' => 'RELATORIOSITFIS92',
            'http_status' => 200,
            'protocol' => null,
            'eta' => null,
            'body' => [
                'obtained' => true,
                'protocol' => ['protocol_id' => 'SITFIS-PROTO-91', 'obtained' => true],
                'dados' => [
                    'protocolo' => 'SITFIS-PROTO-91',
                    'situacao' => $situacao,
                    'nomeArquivoRelatorio' => 'sitfis.pdf',
                ],
            ],
        ];
    }
}
