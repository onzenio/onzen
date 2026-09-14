<?php

namespace Tests\Feature;

use App\Enums\MonitoringRunStatus;
use App\Integrations\Serpro\ConsultCatalog;
use App\Jobs\ExecuteSerproJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Services\Monitoring\PgdasdConsultChain;
use App\Services\Monitoring\SerproExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PgdasdConsultChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_index_projection_enqueues_declaration_and_extract_with_parameters(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context();
        $indexRun = $this->indexRun($enrollment);
        $this->publishSnapshot($indexRun, $enrollment);

        $this->chain()->enqueueAfterIndex($indexRun, $enrollment, $this->indexResult());

        $children = $this->childrenOf($enrollment, $indexRun);

        $this->assertCount(2, $children);
        $this->assertSame(['CONSDECREC15', 'CONSEXTRATO16'], $children->pluck('operation_code')->sort()->values()->all());

        $declaration = $children->firstWhere('operation_code', 'CONSDECREC15');
        $this->assertNotNull($declaration);
        $this->assertSame([
            'numeroDeclaracao' => '00000000000000000013',
            'periodoApuracao' => '202601',
        ], $declaration->parameters);
        $this->assertSame($account->id, $declaration->account_id);
        $this->assertSame($enrollment->id, $declaration->enrollment_id);
        $this->assertSame($enrollment->definition_id, $declaration->definition_key);
        $this->assertSame($enrollment->version, $declaration->fencing_token);
        $this->assertSame($indexRun->environment, $declaration->environment);
        $this->assertSame($indexRun->dry_run, $declaration->dry_run);
        $this->assertSame(MonitoringRunStatus::Pending, $declaration->status);
        $this->assertSame(MonitoringRun::TRIGGER_AUTOMATIC, $declaration->trigger);
        $this->assertSame('CONSDECREC15', $declaration->external_code);

        $extract = $children->firstWhere('operation_code', 'CONSEXTRATO16');
        $this->assertNotNull($extract);
        $this->assertSame([
            'numeroDas' => '85800000000000000013',
        ], $extract->parameters);
        $this->assertSame($account->id, $extract->account_id);
        $this->assertSame($enrollment->id, $extract->enrollment_id);
        $this->assertSame($enrollment->version, $extract->fencing_token);
        $this->assertSame('CONSEXTRATO16', $extract->external_code);

        Queue::assertPushed(ExecuteSerproJob::class, 2);
        Queue::assertPushed(ExecuteSerproJob::class, fn (ExecuteSerproJob $job): bool => $job->runId === $declaration->id);
        Queue::assertPushed(ExecuteSerproJob::class, fn (ExecuteSerproJob $job): bool => $job->runId === $extract->id);
    }

    public function test_the_chain_enqueues_the_latest_declaration_without_a_declaration_number(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context();
        $indexRun = $this->indexRun($enrollment);
        $this->publishSnapshot($indexRun, $enrollment);

        $this->chain()->enqueueAfterIndex($indexRun, $enrollment, [
            'source' => 'fixture',
            'operation_code' => 'CONSDECLARACAO13',
            'body' => ['dados' => [
                'anoCalendario' => '2026',
                'declaracoes' => [['periodoApuracao' => '202601']],
            ]],
        ]);

        $children = $this->childrenOf($enrollment, $indexRun);

        $this->assertCount(1, $children);
        $latest = $children->first();
        $this->assertSame('CONSULTIMADECREC14', $latest->operation_code);
        $this->assertSame(['anoCalendario' => '2026'], $latest->parameters);
        Queue::assertPushed(ExecuteSerproJob::class, 1);
    }

    public function test_an_index_projection_without_declaration_data_enqueues_nothing(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context();
        $indexRun = $this->indexRun($enrollment);
        $this->publishSnapshot($indexRun, $enrollment);

        $this->chain()->enqueueAfterIndex($indexRun, $enrollment, [
            'source' => 'fixture',
            'operation_code' => 'CONSDECLARACAO13',
            'body' => ['dados' => []],
        ]);

        $this->assertCount(0, $this->childrenOf($enrollment, $indexRun));
        Queue::assertNothingPushed();
    }

    public function test_an_unchanged_index_projection_does_not_enqueue(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context();
        $indexRun = $this->indexRun($enrollment);

        $this->chain()->enqueueAfterIndex($indexRun, $enrollment, $this->indexResult());

        $this->assertCount(0, $this->childrenOf($enrollment, $indexRun));
        Queue::assertNothingPushed();
    }

    public function test_replaying_the_index_chain_does_not_duplicate_children(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context();
        $indexRun = $this->indexRun($enrollment);
        $this->publishSnapshot($indexRun, $enrollment);

        $chain = $this->chain();
        $chain->enqueueAfterIndex($indexRun, $enrollment, $this->indexResult());
        $chain->enqueueAfterIndex($indexRun, $enrollment, $this->indexResult());

        $this->assertCount(2, $this->childrenOf($enrollment, $indexRun));
        Queue::assertPushed(ExecuteSerproJob::class, 2);
    }

    public function test_no_chain_for_non_pgdasd_definitions_or_operations(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context('defis');

        $defisRun = $this->indexRun($enrollment, 'CONSDECLARACAO142');
        $this->publishSnapshot($defisRun, $enrollment);
        $this->chain()->enqueueAfterIndex($defisRun, $enrollment, [
            'source' => 'fixture',
            'operation_code' => 'CONSDECLARACAO142',
            'body' => ['dados' => ['declaracoes' => [['numeroDeclaracao' => '142', 'numeroDas' => '858']]]],
        ]);

        $this->assertCount(0, $this->childrenOf($enrollment, $defisRun));

        [$account, $client, $enrollment] = $this->context();
        $childRun = $this->indexRun($enrollment, 'CONSDECREC15');
        $this->publishSnapshot($childRun, $enrollment);
        $this->chain()->enqueueAfterIndex($childRun, $enrollment, $this->indexResult());

        $this->assertCount(0, $this->childrenOf($enrollment, $childRun));
        Queue::assertNothingPushed();
    }

    public function test_a_paused_enrollment_is_not_chained(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context(attributes: ['status' => MonitoringEnrollment::STATUS_PAUSED, 'pause_reason' => 'outorga pendente']);
        $indexRun = $this->indexRun($enrollment);
        $this->publishSnapshot($indexRun, $enrollment);

        $this->chain()->enqueueAfterIndex($indexRun, $enrollment, $this->indexResult());

        $this->assertCount(0, $this->childrenOf($enrollment, $indexRun));
        Queue::assertNothingPushed();
    }

    public function test_the_executor_completion_enqueues_children_once_and_skips_an_unchanged_index(): void
    {
        Queue::fake();
        [$account, $client, $enrollment] = $this->context();
        $executor = app(SerproExecutor::class);

        $first = $executor->execute($executor->claim($enrollment, 'index-first'));
        $this->assertSame(MonitoringRunStatus::Completed, $first->status);
        $this->assertSame(2, $this->childrenOf($enrollment, $first)->count());

        $second = $executor->execute($executor->claim($enrollment, 'index-second'));
        $this->assertSame(MonitoringRunStatus::Completed, $second->status);

        // The second index returned the same fiscal content: no new snapshot
        // for its run and therefore no new children.
        $this->assertSame(2, $this->childrenOf($enrollment, $first)->count());
        $this->assertDatabaseCount('monitoring_snapshots', 1);
        Queue::assertPushed(ExecuteSerproJob::class, 2);
    }

    public function test_the_pj_only_gate_lives_in_the_catalog_and_enrollment_not_the_chain(): void
    {
        // The legacy chain carried no person-type check: the PGDAS-D
        // definition is PJ-only in the catalog and the enrollment service
        // enforces it before a run exists. The chain must not second-guess it.
        $this->assertSame(['PJ'], ConsultCatalog::personTypesFor('pgdas-declaracoes'));

        Queue::fake();
        [$account, $client, $enrollment, $definition] = $this->context();
        $this->assertSame(['PJ'], $definition->person_types);

        $indexRun = $this->indexRun($enrollment);
        $this->publishSnapshot($indexRun, $enrollment);

        $this->chain()->enqueueAfterIndex($indexRun, $enrollment, $this->indexResult());

        $this->assertCount(2, $this->childrenOf($enrollment, $indexRun));
    }

    private function chain(): PgdasdConsultChain
    {
        return app(PgdasdConsultChain::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Account, 1: Client, 2: MonitoringEnrollment, 3: MonitoringDefinition}
     */
    private function context(string $definitionId = 'pgdas-declaracoes', array $attributes = []): array
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $definition = MonitoringDefinition::factory()->create([
            'id' => $definitionId,
            'operations' => ConsultCatalog::operationsFor($definitionId) ?? ['CONSDECLARACAO13'],
            'person_types' => ConsultCatalog::personTypesFor($definitionId) ?? ['PF', 'PJ'],
        ]);
        $enrollment = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            ...$attributes,
        ]);

        return [$account, $client, $enrollment, $definition];
    }

    private function indexRun(MonitoringEnrollment $enrollment, string $operation = 'CONSDECLARACAO13'): MonitoringRun
    {
        return MonitoringRun::factory()->create([
            'account_id' => $enrollment->account_id,
            'enrollment_id' => $enrollment->id,
            'definition_key' => $enrollment->definition_id,
            'operation_code' => $operation,
            'external_code' => $operation,
            'fencing_token' => $enrollment->version,
            'status' => MonitoringRunStatus::Completed,
            'environment' => 'homologacao',
            'dry_run' => true,
            'parameters' => null,
        ]);
    }

    private function publishSnapshot(MonitoringRun $run, MonitoringEnrollment $enrollment): MonitoringSnapshot
    {
        return MonitoringSnapshot::factory()->create([
            'account_id' => $enrollment->account_id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $enrollment->client_id,
            'run_id' => $run->getKey(),
            'operation_code' => $run->operation_code,
            'family' => 'pgdasd',
            'data' => ['ano_calendario' => '2026', 'declaracoes' => []],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function indexResult(): array
    {
        return [
            'source' => 'fixture',
            'operation_code' => 'CONSDECLARACAO13',
            'body' => [
                'status' => 'Sucesso-PGDASD',
                'codigo' => 'Sucesso-PGDASD',
                'dados' => [
                    'anoCalendario' => '2026',
                    'declaracoes' => [[
                        'periodoApuracao' => '202601',
                        'numeroDeclaracao' => '00000000000000000013',
                        'numeroDas' => '85800000000000000013',
                    ]],
                ],
            ],
        ];
    }

    /**
     * @return Collection<int, MonitoringRun>
     */
    private function childrenOf(MonitoringEnrollment $enrollment, MonitoringRun $parent)
    {
        return MonitoringRun::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereKeyNot($parent->getKey())
            ->whereIn('operation_code', [
                PgdasdConsultChain::DECLARATION_OPERATION,
                PgdasdConsultChain::LATEST_DECLARATION_OPERATION,
                PgdasdConsultChain::EXTRACT_OPERATION,
            ])
            ->orderBy('id')
            ->get();
    }
}
