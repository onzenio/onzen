<?php

namespace Tests\Feature;

use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\ConsultOperationResolver;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\MonitoringDefinition;
use Database\Seeders\MonitoringDefinitionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class MonitoringDefinitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_definitions_table_has_expected_columns_and_no_account_scope(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_definitions', [
            'id', 'name', 'category', 'system', 'description', 'version', 'availability',
            'strategy', 'default_enabled', 'is_active', 'requires_procuracao',
            'operations', 'procuration_codes', 'person_types', 'regimes', 'services',
            'created_at', 'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('monitoring_definitions', 'account_id'));
    }

    public function test_definition_persists_json_columns_with_casts(): void
    {
        $definition = MonitoringDefinition::factory()->create([
            'operations' => ['CONSDECLARACAO13'],
            'procuration_codes' => ['00146'],
            'person_types' => ['PJ'],
            'regimes' => ['Simples Nacional'],
            'services' => ['PGDASD'],
            'default_enabled' => true,
            'requires_procuracao' => true,
        ]);

        $definition->refresh();

        $this->assertSame(['CONSDECLARACAO13'], $definition->operations);
        $this->assertSame(['00146'], $definition->procuration_codes);
        $this->assertSame(['PJ'], $definition->person_types);
        $this->assertSame(['Simples Nacional'], $definition->regimes);
        $this->assertSame(['PGDASD'], $definition->services);
        $this->assertTrue($definition->default_enabled);
        $this->assertTrue($definition->is_active);
        $this->assertTrue($definition->requires_procuracao);
        $this->assertTrue($definition->isAvailable());
    }

    public function test_definition_availability_and_strategy_defaults(): void
    {
        $definition = MonitoringDefinition::factory()->create();

        $this->assertSame('production', $definition->availability);
        $this->assertSame('polling', $definition->strategy);
        $this->assertSame(MonitoringDefinition::CATALOG_VERSION, $definition->version);
    }

    public function test_factory_states_mark_definitions_unavailable(): void
    {
        $prospeccao = MonitoringDefinition::factory()->prospeccao()->create();
        $unavailable = MonitoringDefinition::factory()->unavailable()->create();

        $this->assertSame(MonitoringDefinition::AVAILABILITY_PROSPECCAO, $prospeccao->availability);
        $this->assertFalse($prospeccao->isAvailable());
        $this->assertNull($prospeccao->operations);
        $this->assertSame('unavailable', $unavailable->availability);
        $this->assertFalse($unavailable->isAvailable());
        $this->assertNull($unavailable->operations);
    }

    public function test_seeder_reproduces_official_consult_operations(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $expected = [
            'pgdas-declaracoes' => ['CONSDECLARACAO13', 'CONSULTIMADECREC14', 'CONSDECREC15', 'CONSEXTRATO16'],
            'regime-apuracao' => ['CONSULTARANOSCALENDARIOS102', 'CONSULTAROPCAOREGIME103', 'CONSULTARRESOLUCAO104'],
            'defis' => ['CONSDECLARACAO142', 'CONSULTIMADECREC143', 'CONSDECREC144'],
            'situacao-mei' => ['DIVIDAATIVA24', 'DADOSCCMEI122', 'CCMEISITCADASTRAL123'],
            'dctfweb' => ['CONSRECIBO32', 'CONSDECCOMPLETA33', 'CONSXMLDECLARACAO38'],
            'mit' => ['SITUACAOENC315', 'CONSAPURACAO316', 'LISTAAPURACOES317'],
            'situacao-fiscal' => ['SOLICITARPROTOCOLO91', 'RELATORIOSITFIS92'],
            'caixa-postal' => ['MSGCONTRIBUINTE61', 'MSGDETALHAMENTO62', 'INNOVAMSG63'],
            'dte' => ['CONSULTASITUACAODTE111'],
            'pagamentos' => ['PAGAMENTOS71', 'CONTACONSDOCARRPG73'],
        ];

        foreach ($expected as $definitionId => $operations) {
            $definition = MonitoringDefinition::query()->findOrFail($definitionId);
            $this->assertSame($operations, $definition->operations, $definitionId);
            $this->assertSame('production', $definition->availability, $definitionId);
            $this->assertSame(MonitoringDefinition::CATALOG_VERSION, $definition->version, $definitionId);
            $this->assertTrue($definition->is_active, $definitionId);

            $encoded = (string) json_encode($definition->operations);
            $this->assertStringNotContainsString('TRANSDECLARACAO', $encoded, $definitionId);
            $this->assertStringNotContainsString('GERARDAS12', $encoded, $definitionId);
        }

        $this->assertSame(['PJ'], MonitoringDefinition::query()->findOrFail('pgdas-declaracoes')->person_types);
        $this->assertSame(['PF', 'PJ'], MonitoringDefinition::query()->findOrFail('situacao-mei')->person_types);
    }

    public function test_seeder_seeds_all_eight_parcelment_modalities_and_prospecting_rows(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        foreach (ConsultCatalog::PARCELMENT_OPERATIONS as $modality => $operations) {
            $definition = MonitoringDefinition::query()->findOrFail($modality);
            $this->assertSame($operations, $definition->operations, $modality);
            $this->assertSame('Parcelamentos', $definition->category, $modality);
            $this->assertSame('production', $definition->availability, $modality);
            $this->assertTrue($definition->isAvailable(), $modality);
        }

        $this->assertSame(8, MonitoringDefinition::query()->where('category', 'Parcelamentos')->where('availability', 'production')->count());

        foreach (ConsultCatalog::PROSPECCAO_DEFINITIONS as $definitionId) {
            $definition = MonitoringDefinition::query()->findOrFail($definitionId);
            $this->assertSame(MonitoringDefinition::AVAILABILITY_PROSPECCAO, $definition->availability, $definitionId);
            $this->assertNull($definition->operations, $definitionId);
            $this->assertFalse($definition->isAvailable(), $definitionId);
        }
    }

    public function test_seeder_spot_checks_official_procuration_codes(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $expected = [
            'pgdas-declaracoes' => ['00146'],
            'defis' => ['00146'],
            'regime-apuracao' => ['00060'],
            'dctfweb' => ['00103'],
            'mit' => ['00103'],
            'situacao-fiscal' => ['00002'],
            'caixa-postal' => ['00006'],
            'dte' => ['00050'],
            'pagamentos' => ['00004'],
            'situacao-mei' => [],
            'parcsn' => ['00076', '00188'],
            'parcsn-esp' => ['00125'],
            'pertsn' => ['00149', '10011'],
            'relpsn' => ['00210', '10036'],
            'parcmei' => ['00134'],
            'parcmei-esp' => ['00133'],
            'pertmei' => ['00152', '10012'],
            'relpmei' => ['00209', '10035'],
        ];

        foreach ($expected as $definitionId => $codes) {
            $definition = MonitoringDefinition::query()->findOrFail($definitionId);
            $this->assertSame($codes, $definition->procuration_codes, $definitionId);
        }

        $this->assertFalse((bool) MonitoringDefinition::query()->findOrFail('situacao-mei')->requires_procuracao);
        $this->assertTrue((bool) MonitoringDefinition::query()->findOrFail('pgdas-declaracoes')->requires_procuracao);
        $this->assertTrue((bool) MonitoringDefinition::query()->findOrFail('parcsn')->requires_procuracao);
    }

    public function test_seeder_is_idempotent_and_upserts_existing_rows(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $count = MonitoringDefinition::query()->count();

        MonitoringDefinition::query()->whereKey('pgdas-declaracoes')->update(['operations' => json_encode(['WRONG'])]);
        MonitoringDefinition::query()->whereKey('parc-sipade')->update([
            'operations' => json_encode(['PEDIDOSPARC999']),
            'availability' => 'production',
        ]);

        $this->seed(MonitoringDefinitionSeeder::class);

        $this->assertSame($count, MonitoringDefinition::query()->count());
        $this->assertSame(1, MonitoringDefinition::query()->whereKey('pgdas-declaracoes')->count());
        $this->assertSame(ConsultCatalog::operationsFor('pgdas-declaracoes'), MonitoringDefinition::query()->findOrFail('pgdas-declaracoes')->operations);
        $this->assertNull(MonitoringDefinition::query()->findOrFail('parc-sipade')->operations);
        $this->assertSame(MonitoringDefinition::AVAILABILITY_PROSPECCAO, MonitoringDefinition::query()->findOrFail('parc-sipade')->availability);
    }

    public function test_every_available_definition_resolves_an_executable_consult_operation(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $resolver = new ConsultOperationResolver;
        $available = MonitoringDefinition::query()
            ->where('availability', MonitoringDefinition::AVAILABILITY_PRODUCTION)
            ->where('is_active', true)
            ->get();

        $this->assertSame(18, $available->count());

        foreach ($available as $definition) {
            $operation = $resolver->resolve($definition);
            $this->assertNotSame('', $operation, $definition->id);
            $this->assertFalse(ConsultCatalog::isForbiddenPolling($operation), $definition->id);
            $this->assertFalse(ConsultCatalog::isExplicitFiscalAction($operation), $definition->id);
        }
    }

    public function test_prospecting_and_unavailable_definitions_do_not_resolve(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $resolver = new ConsultOperationResolver;

        foreach (ConsultCatalog::PROSPECCAO_DEFINITIONS as $definitionId) {
            try {
                $resolver->resolve(MonitoringDefinition::query()->findOrFail($definitionId));
                $this->fail("Expected prospecting definition {$definitionId} to fail closed.");
            } catch (DomainException $exception) {
                $this->assertSame('parcelment_modality_unavailable', $exception->getMessage());
            }
        }

        $unavailable = MonitoringDefinition::factory()->unavailable()->create([
            'id' => 'unavailable-definition',
            'operations' => ['CONSDECLARACAO13'],
        ]);

        try {
            $resolver->resolve($unavailable);
            $this->fail('Expected unavailable definition to fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('consult_operation_unresolved', $exception->getMessage());
        }
    }

    public function test_every_seeded_available_definition_has_official_procuration_codes(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        foreach (MonitoringDefinition::query()->where('availability', MonitoringDefinition::AVAILABILITY_PRODUCTION)->get() as $definition) {
            $this->assertNotNull($definition->procuration_codes, $definition->id);
            foreach ($definition->procuration_codes as $code) {
                $this->assertTrue(ProcurationCatalog::isAllowedCode($code), "{$definition->id}: {$code}");
            }
        }
    }
}
