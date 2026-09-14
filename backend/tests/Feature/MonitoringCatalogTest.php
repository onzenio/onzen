<?php

namespace Tests\Feature;

use App\Models\MonitoringDefinition;
use App\Services\MonitoringCatalogService;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonitoringCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_definitions', [
            'id', 'code', 'family', 'catalog_version', 'availability', 'operations',
        ]));
    }

    public function test_seed_cobre_disponiveis_indisponiveis_e_prospeccao(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $this->assertGreaterThanOrEqual(10, MonitoringDefinition::query()->where('availability', 'available')->count());
        $this->assertDatabaseHas('monitoring_definitions', ['code' => 'sicalc', 'availability' => 'unavailable']);
        $this->assertDatabaseHas('monitoring_definitions', ['code' => 'e-processo', 'availability' => 'prospecting']);
        $this->assertSame(
            MonitoringDefinitionSeeder::CATALOG_VERSION,
            MonitoringDefinition::query()->where('code', 'pgdasd')->firstOrFail()->catalog_version,
        );
    }

    public function test_resolve_operacao_para_definicao_suportada(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $service = app(MonitoringCatalogService::class);

        $operation = $service->resolveExecutableOperation('pagamentos');

        $this->assertSame('consultar', $operation['type']);
        $this->assertSame('consultar-pagamentos', $operation['operation']);
    }

    public function test_definicao_indisponivel_falha_explicita(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);
        $service = app(MonitoringCatalogService::class);

        foreach (['sicalc', 'e-processo'] as $code) {
            try {
                $service->resolveExecutableOperation($code);
                $this->fail("{$code} deveria falhar de forma explícita");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($code, $e->getMessage());
            }
        }
    }

    public function test_allowlist_contem_servicos_oficiais(): void
    {
        $allowlist = app(MonitoringCatalogService::class)->procurationAllowlist();

        $this->assertContains('PGDASD-CONS', $allowlist);
        $this->assertContains('PARC-CONS', $allowlist);
        $this->assertNotContains('SERVICO-INEXISTENTE', $allowlist);
    }
}
