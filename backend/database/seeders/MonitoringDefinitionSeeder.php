<?php

namespace Database\Seeders;

use App\Models\MonitoringDefinition;
use Illuminate\Database\Seeder;

class MonitoringDefinitionSeeder extends Seeder
{
    public const CATALOG_VERSION = '2026.09';

    /**
     * Allowlist oficial de códigos de serviço aceitos em procurações.
     *
     * @return list<string>
     */
    public static function procurationAllowlist(): array
    {
        return [
            'PGDASD-CONS', 'REGIME-CONS', 'DEFIS-CONS', 'MEI-CONS',
            'DCTFWEB-CONS', 'MIT-CONS', 'SITFIS-CONS', 'CAIXAPOSTAL-CONS',
            'DTE-CONS', 'PAG-CONS', 'PARC-CONS',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $v = self::CATALOG_VERSION;

        return [
            [
                'code' => 'pgdasd', 'family' => 'PGDAS-D', 'name' => 'PGDAS-D',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'chain',
                'person_types' => ['PJ'], 'regimes' => ['simples'],
                'required_services' => ['PGDASD-CONS'],
                'operations' => [
                    ['type' => 'consultar', 'operation' => 'consultar-pgdasd-indice'],
                    ['type' => 'consultar', 'operation' => 'consultar-pgdasd-declaracao'],
                    ['type' => 'consultar', 'operation' => 'consultar-pgdasd-extrato'],
                ],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'regime', 'family' => 'Regime de Apuração', 'name' => 'Regime de Apuração',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ'], 'regimes' => ['simples', 'presumido', 'real'],
                'required_services' => ['REGIME-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-regime']],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'defis', 'family' => 'DEFIS', 'name' => 'DEFIS',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ'], 'regimes' => ['simples'],
                'required_services' => ['DEFIS-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-defis']],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'mei', 'family' => 'MEI', 'name' => 'MEI',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ'], 'regimes' => ['mei'],
                'required_services' => ['MEI-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-mei']],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'dctfweb', 'family' => 'DCTFWeb', 'name' => 'DCTFWeb',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ', 'PF'], 'regimes' => ['simples', 'presumido', 'real'],
                'required_services' => ['DCTFWEB-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-dctfweb']],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'mit', 'family' => 'MIT', 'name' => 'MIT',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ'], 'regimes' => ['simples', 'presumido', 'real'],
                'required_services' => ['MIT-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-mit']],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'sitfis', 'family' => 'Situação Fiscal', 'name' => 'Situação Fiscal',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ', 'PF'], 'regimes' => ['simples', 'presumido', 'real', 'mei'],
                'required_services' => ['SITFIS-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-sitfis']],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'caixa-postal', 'family' => 'Caixa Postal', 'name' => 'Caixa Postal',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ', 'PF'], 'regimes' => ['simples', 'presumido', 'real', 'mei'],
                'required_services' => ['CAIXAPOSTAL-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-caixa-postal']],
                'automatic' => false, 'unavailability_reason' => null,
            ],
            [
                'code' => 'dte', 'family' => 'DTE', 'name' => 'DTE',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ', 'PF'], 'regimes' => ['simples', 'presumido', 'real', 'mei'],
                'required_services' => ['DTE-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-dte']],
                'automatic' => false, 'unavailability_reason' => null,
            ],
            [
                'code' => 'pagamentos', 'family' => 'Pagamentos', 'name' => 'Pagamentos',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ', 'PF'], 'regimes' => ['simples', 'presumido', 'real', 'mei'],
                'required_services' => ['PAG-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-pagamentos']],
                'automatic' => true, 'unavailability_reason' => null,
            ],
            [
                'code' => 'parcelamentos', 'family' => 'Parcelamentos', 'name' => 'Parcelamentos',
                'catalog_version' => $v, 'availability' => 'available', 'strategy' => 'snapshot',
                'person_types' => ['PJ', 'PF'], 'regimes' => ['simples', 'presumido', 'real', 'mei'],
                'required_services' => ['PARC-CONS'],
                'operations' => [['type' => 'consultar', 'operation' => 'consultar-parcelamentos']],
                'automatic' => false, 'unavailability_reason' => null,
            ],
            [
                'code' => 'sicalc', 'family' => 'SICALC', 'name' => 'SICALC',
                'catalog_version' => $v, 'availability' => 'unavailable', 'strategy' => 'snapshot',
                'person_types' => ['PJ'], 'regimes' => ['simples', 'presumido', 'real'],
                'required_services' => [],
                'operations' => [],
                'automatic' => false,
                'unavailability_reason' => 'Família fora do catálogo do Integra Contador nesta versão.',
            ],
            [
                'code' => 'e-processo', 'family' => 'E-Processo', 'name' => 'E-Processo',
                'catalog_version' => $v, 'availability' => 'prospecting', 'strategy' => 'snapshot',
                'person_types' => ['PJ'], 'regimes' => ['simples', 'presumido', 'real'],
                'required_services' => [],
                'operations' => [],
                'automatic' => false,
                'unavailability_reason' => 'Em prospecção: sem operação executável.',
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            MonitoringDefinition::query()->updateOrCreate(
                ['code' => $definition['code']],
                $definition,
            );
        }
    }
}
