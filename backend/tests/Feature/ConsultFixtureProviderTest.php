<?php

namespace Tests\Feature;

use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\ConsultFixtureProvider;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class ConsultFixtureProviderTest extends TestCase
{
    /**
     * Official operations without a fixture in the legacy source set.
     *
     * @var list<string>
     */
    private const OPERATIONS_WITHOUT_FIXTURE = ['PAGAMENTOS71', 'CONTACONSDOCARRPG73'];

    /**
     * @var list<string>
     */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_every_consult_operation_loads_its_official_fixture_with_base_fields(): void
    {
        $provider = new ConsultFixtureProvider;

        foreach (array_merge(...array_values(ConsultCatalog::CONSULT_OPERATIONS)) as $operation) {
            $fixture = $provider->load($operation);

            if (in_array($operation, self::OPERATIONS_WITHOUT_FIXTURE, true)) {
                $this->assertNull($fixture, $operation);

                continue;
            }

            $this->assertIsArray($fixture, $operation);
            $this->assertSame(ConsultCatalog::fixtureKey($operation), $fixture['operation'], $operation);
            $this->assertIsString($fixture['source'], $operation);
            $this->assertNotSame('', $fixture['source'], $operation);
            $this->assertIsInt($fixture['http_status'], $operation);
            $this->assertGreaterThanOrEqual(200, $fixture['http_status'], $operation);
            $this->assertLessThan(300, $fixture['http_status'], $operation);
            $this->assertTrue($fixture['dry_run'], $operation);
            $this->assertIsArray($fixture['body'], $operation);
            $this->assertArrayHasKey('dados', $fixture['body'], $operation);
        }
    }

    public function test_each_family_loads_a_fixture_with_its_official_body_section(): void
    {
        $provider = new ConsultFixtureProvider;

        $families = [
            'pgdasd' => 'CONSDECLARACAO13',
            'regime' => 'CONSULTAROPCAOREGIME103',
            'defis' => 'CONSDECLARACAO142',
            'mei' => 'DADOSCCMEI122',
            'dctfweb' => 'CONSRECIBO32',
            'sitfis' => 'RELATORIOSITFIS92',
            'caixa_postal' => 'MSGCONTRIBUINTE61',
        ];

        foreach ($families as $family => $operation) {
            $fixture = $provider->load($operation);

            $this->assertSame($family, ConsultCatalog::familyFor($operation), $operation);
            $this->assertIsArray($fixture, $operation);
            $this->assertArrayHasKey('dados', $fixture['body'], $operation);
        }

        foreach (['SITUACAOENC315', 'CONSAPURACAO316', 'LISTAAPURACOES317'] as $operation) {
            $fixture = $provider->load($operation);

            $this->assertSame('dctfweb', ConsultCatalog::familyFor($operation), $operation);
            $this->assertIsArray($fixture, $operation);
            $this->assertArrayHasKey('dados', $fixture['body'], $operation);
        }

        $dte = $provider->load('CONSULTASITUACAODTE111');
        $this->assertIsArray($dte);
        $this->assertArrayHasKey('dados', $dte['body']);

        foreach (self::OPERATIONS_WITHOUT_FIXTURE as $operation) {
            $this->assertNull($provider->load($operation), $operation);
        }
    }

    public function test_parcelment_generic_keys_resolve_for_the_eight_modalities(): void
    {
        $provider = new ConsultFixtureProvider;
        $operations = 0;

        foreach (ConsultCatalog::PARCELMENT_OPERATIONS as $modality => $codes) {
            foreach ($codes as $code) {
                if (! ConsultCatalog::isParcelmentConsult($code)) {
                    continue;
                }

                $fixture = $provider->load($code);

                $this->assertIsArray($fixture, $code);
                $this->assertSame(ConsultCatalog::fixtureKey($code), $fixture['operation'], $code);
                $this->assertArrayHasKey('dados', $fixture['body'], $code);
                $operations++;
            }
        }

        $this->assertSame(8, count(ConsultCatalog::PARCELMENT_MODALITIES));
        $this->assertSame(32, $operations);
    }

    public function test_operation_without_fixture_returns_null(): void
    {
        $provider = new ConsultFixtureProvider;

        $this->assertNull($provider->load('PAGAMENTOS71'));
        $this->assertNull($provider->load('UNKNOWN999'));
        $this->assertNull($provider->load('CONSDECLARACAO99'));
    }

    public function test_missing_fixture_directory_returns_null(): void
    {
        config()->set('monitoring.fixtures_path', 'resources/fixtures/serpro/does-not-exist');

        $this->assertNull((new ConsultFixtureProvider)->load('CONSDECLARACAO13'));
    }

    public function test_unsafe_fixture_keys_are_rejected(): void
    {
        $provider = new ConsultFixtureProvider;

        $this->assertNull($provider->load('../../CONSDECLARACAO13'));
        $this->assertNull($provider->load('CONSDECLARACAO13/../CONSDECLARACAO13'));
        $this->assertNull($provider->load('CONSDECLARACAO13.JSON'));
    }

    public function test_malformed_fixture_fails_explicitly(): void
    {
        $directory = $this->createTempFixtureDirectory();
        File::put($directory.'/CONSDECLARACAO13.json', '{"operation":');

        config()->set('monitoring.fixtures_path', $directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed consult fixture JSON');

        (new ConsultFixtureProvider)->load('CONSDECLARACAO13');
    }

    public function test_fixture_that_is_not_a_json_object_fails_explicitly(): void
    {
        $directory = $this->createTempFixtureDirectory();
        File::put($directory.'/CONSDECLARACAO13.json', 'null');

        config()->set('monitoring.fixtures_path', $directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed consult fixture JSON');

        (new ConsultFixtureProvider)->load('CONSDECLARACAO13');
    }

    public function test_declaration_and_forbidden_operations_are_rejected(): void
    {
        $provider = new ConsultFixtureProvider;

        $rejected = [
            'TRANSDECLARACAO11',
            'TRANSDECLARACAO141',
            'TRANSDECLARACAO310',
            'GERARDAS12',
            'GERARDAS161',
            'EFETUAROPCAOREGIME101',
            'EMITIRCCMEI121',
            'retorno_entregar_declaracao',
            'ENTREGARQUALQUERCOISA',
        ];

        foreach ($rejected as $operation) {
            $this->assertNull($provider->load($operation), $operation);
        }
    }

    public function test_declaration_payload_inside_a_fixture_is_rejected(): void
    {
        $directory = $this->createTempFixtureDirectory();
        File::put($directory.'/CONSDECLARACAO13.json', json_encode([
            'source' => 'test',
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 200,
            'body' => ['retorno_entregar_declaracao' => ['protocolo' => '00000000000000000000']],
        ], JSON_THROW_ON_ERROR));

        config()->set('monitoring.fixtures_path', $directory);

        $this->assertNull((new ConsultFixtureProvider)->load('CONSDECLARACAO13'));
    }

    public function test_poll_of_solicitar_protocolo_uses_the_report_fixture(): void
    {
        $provider = new ConsultFixtureProvider;

        $request = $provider->load('SOLICITARPROTOCOLO91');
        $this->assertIsArray($request);
        $this->assertSame('SOLICITARPROTOCOLO91', $request['operation']);
        $this->assertSame(202, $request['http_status']);

        $report = $provider->load('SOLICITARPROTOCOLO91', true);
        $this->assertIsArray($report);
        $this->assertSame('RELATORIOSITFIS92', $report['operation']);
        $this->assertArrayHasKey('protocol', $report['body']);
    }

    public function test_absolute_fixture_path_override_is_resolved(): void
    {
        $directory = $this->createTempFixtureDirectory();
        File::put($directory.'/CONSDECLARACAO13.json', json_encode([
            'source' => 'test',
            'operation' => 'CONSDECLARACAO13',
            'dry_run' => true,
            'http_status' => 200,
            'body' => ['dados' => []],
        ], JSON_THROW_ON_ERROR));

        config()->set('monitoring.fixtures_path', $directory);

        $fixture = (new ConsultFixtureProvider)->load(' consdeclaracao13 ');

        $this->assertIsArray($fixture);
        $this->assertSame('CONSDECLARACAO13', $fixture['operation']);
    }

    public function test_official_fixture_files_are_dry_run_consult_examples(): void
    {
        $directory = base_path((string) config('monitoring.fixtures_path'));
        $files = glob($directory.'/*.json') ?: [];

        $this->assertCount(29, $files);

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('retorno_entregar_declaracao', $contents, $file);
            $this->assertStringNotContainsString('TRANSDECLARACAO11', $contents, $file);
            $this->assertStringNotContainsString('entregar_declaracao', $contents, $file);

            $decoded = json_decode($contents, true);

            $this->assertIsArray($decoded, $file);
            $this->assertTrue($decoded['dry_run'] ?? false, $file);
            $this->assertSame(basename($file, '.json'), $decoded['operation'] ?? null, $file);
        }
    }

    private function createTempFixtureDirectory(): string
    {
        $directory = storage_path('framework/testing/consult-fixtures-'.uniqid());
        File::ensureDirectoryExists($directory);
        $this->tempDirectories[] = $directory;

        return $directory;
    }
}
