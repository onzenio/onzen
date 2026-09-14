<?php

namespace Tests\Feature;

use App\Integrations\Serpro\SerproFixtureProvider;
use App\Integrations\Serpro\SerproNormalizer;
use Tests\TestCase;

class SerproNormalizerTest extends TestCase
{
    public function test_normaliza_por_familia_com_fixtures_oficiais(): void
    {
        $provider = app(SerproFixtureProvider::class);

        $expectations = [
            'consultar-pgdasd-indice' => ['family' => 'pgdasd'],
            'consultar-pgdasd-declaracao' => ['family' => 'pgdasd', 'recibo' => 'REC-SINTETICO-001'],
            'consultar-pgdasd-extrato' => ['family' => 'pgdasd', 'valor_apurado' => '1000.00'],
            'consultar-regime' => ['family' => 'regime', 'regime_apuracao' => 'simples'],
            'consultar-defis' => ['family' => 'defis', 'situacao' => 'entregue'],
            'consultar-mei' => ['family' => 'mei', 'situacao' => 'optante'],
            'consultar-dctfweb' => ['family' => 'dctfweb', 'competencia' => '2025-01'],
            'consultar-mit' => ['family' => 'mit', 'competencia' => '2025-01'],
            'consultar-sitfis' => ['family' => 'sitfis', 'situacao_fiscal' => 'regular'],
            'consultar-caixa-postal' => ['family' => 'caixa-postal', 'nao_lidas' => 1],
            'consultar-dte' => ['family' => 'dte', 'mensagens_total' => 1],
            'consultar-pagamentos' => ['family' => 'pagamentos', 'pagamentos_total' => 1],
        ];

        foreach ($expectations as $operation => $expected) {
            $fixture = $provider->load($operation);
            $family = SerproNormalizer::familyForOperation($operation);
            $normalized = SerproNormalizer::normalize($family, $fixture['payload']);

            $this->assertTrue($normalized['normalized'], $operation);
            foreach ($expected as $key => $value) {
                $this->assertSame($value, $normalized[$key], "{$operation}.{$key}");
            }
        }
    }

    public function test_campos_ausentes_ficam_nulos_sem_inventar(): void
    {
        $normalized = SerproNormalizer::normalize('dctfweb', []);

        $this->assertTrue($normalized['normalized']);
        $this->assertNull($normalized['competencia']);
        $this->assertNull($normalized['situacao']);
    }

    public function test_familia_sem_normalizador_e_sinalizada(): void
    {
        $normalized = SerproNormalizer::normalize('sicalc', ['qualquer' => 'coisa']);

        $this->assertFalse($normalized['normalized']);
        $this->assertSame('sicalc', $normalized['family']);
        $this->assertArrayNotHasKey('qualquer', $normalized);
    }
}
