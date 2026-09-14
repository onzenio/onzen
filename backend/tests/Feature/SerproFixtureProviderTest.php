<?php

namespace Tests\Feature;

use App\Integrations\Serpro\SerproFixtureProvider;
use Tests\TestCase;

class SerproFixtureProviderTest extends TestCase
{
    /**
     * @return list<string>
     */
    public static function operations(): array
    {
        return [
            'consultar-pgdasd-indice',
            'consultar-pgdasd-declaracao',
            'consultar-pgdasd-extrato',
            'consultar-regime',
            'consultar-defis',
            'consultar-mei',
            'consultar-dctfweb',
            'consultar-mit',
            'consultar-sitfis',
            'consultar-caixa-postal',
            'consultar-dte',
            'consultar-pagamentos',
            'consultar-parcelamentos',
        ];
    }

    public function test_carrega_fixture_por_operacao(): void
    {
        $provider = app(SerproFixtureProvider::class);

        foreach (self::operations() as $operation) {
            $fixture = $provider->load($operation);

            $this->assertSame($operation, $fixture['operation'], $operation);
            $this->assertTrue((bool) $fixture['dry_run'], $operation);
            $this->assertArrayHasKey('payload', $fixture, $operation);
        }
    }

    public function test_fixture_ausente_falha_de_forma_explicita(): void
    {
        $provider = app(SerproFixtureProvider::class);

        try {
            $provider->load('consultar-operacao-inexistente');
            $this->fail('Deveria falhar sem inventar resultado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('consultar-operacao-inexistente', $e->getMessage());
            $this->assertStringContainsString('Sem fixture', $e->getMessage());
        }
    }

    public function test_lista_operacoes_disponiveis(): void
    {
        $available = app(SerproFixtureProvider::class)->availableOperations();

        foreach (self::operations() as $operation) {
            $this->assertContains($operation, $available);
        }
    }
}
