<?php

namespace Database\Seeders;

use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\MonitoringDefinition;
use Illuminate\Database\Seeder;

/**
 * Reproduces the official Integra Contador catalog for the change's scope:
 * consult definitions, eight parcelment modalities and the two prospecting
 * modalities. Upserts are idempotent; operations come from the catalog
 * classes, never copied by hand.
 */
class MonitoringDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'id' => 'pgdas-declaracoes',
                'name' => 'Declarações PGDAS-D',
                'category' => 'Declarações',
                'system' => 'Integra Contador',
                'description' => 'Acompanha declarações, recibos e extratos do Simples Nacional.',
                'default_enabled' => true,
                'is_active' => true,
                'requires_procuracao' => true,
                'procuration_codes' => ['00146'],
                'strategy' => MonitoringDefinition::STRATEGY_AUTOMATIC,
            ],
            [
                'id' => 'regime-apuracao',
                'name' => 'Regime de apuração',
                'category' => 'Regime',
                'system' => 'Integra Contador',
                'description' => 'Compara opção, ano-calendário e resoluções do contribuinte.',
                'default_enabled' => true,
                'is_active' => true,
                'requires_procuracao' => true,
                'procuration_codes' => ['00060'],
            ],
            [
                'id' => 'defis',
                'name' => 'DEFIS',
                'category' => 'Declarações',
                'system' => 'Integra Contador',
                'description' => 'Declaração DEFIS.',
                'default_enabled' => true,
                'is_active' => true,
                'requires_procuracao' => true,
                'procuration_codes' => ['00146'],
            ],
            [
                'id' => 'dctfweb',
                'name' => 'DCTFWeb',
                'category' => 'Obrigações',
                'system' => 'Integra Contador',
                'description' => 'Sinaliza mudanças em apurações e recibos autorizados.',
                'default_enabled' => true,
                'is_active' => true,
                'requires_procuracao' => true,
                'strategy' => MonitoringDefinition::STRATEGY_AUTOMATIC,
            ],
            [
                'id' => 'situacao-fiscal',
                'name' => 'Situação Fiscal',
                'category' => 'Regularidade',
                'system' => 'Integra Contador',
                'description' => 'Acompanha relatórios assíncronos de situação fiscal.',
                'default_enabled' => false,
                'is_active' => true,
                'requires_procuracao' => true,
                'strategy' => MonitoringDefinition::STRATEGY_AUTOMATIC,
            ],
        ];

        $definitions = array_merge($definitions, [
            [
                'id' => 'situacao-mei',
                'name' => 'Situação MEI',
                'category' => 'Simples Nacional | MEI',
                'system' => 'Integra Contador',
                'description' => 'Situação do MEI.',
                'default_enabled' => true,
                'is_active' => true,
                'requires_procuracao' => false,
                'procuration_codes' => [],
            ],
            [
                'id' => 'mit',
                'name' => 'MIT',
                'category' => 'Obrigações',
                'system' => 'Integra Contador',
                'description' => 'Módulo de inclusão de tributos.',
                'default_enabled' => false,
                'is_active' => true,
                'requires_procuracao' => true,
                'procuration_codes' => ['00103'],
            ],
            [
                'id' => 'caixa-postal',
                'name' => 'Caixa Postal',
                'category' => 'Caixas Postais',
                'system' => 'Integra Contador',
                'description' => 'Mensagens da caixa postal.',
                'default_enabled' => false,
                'is_active' => true,
                'requires_procuracao' => true,
                'procuration_codes' => ['00006'],
            ],
            [
                'id' => 'dte',
                'name' => 'DTE',
                'category' => 'Caixas Postais',
                'system' => 'Integra Contador',
                'description' => 'Domicílio tributário eletrônico.',
                'default_enabled' => false,
                'is_active' => true,
                'requires_procuracao' => true,
                'procuration_codes' => ['00050'],
            ],
            [
                'id' => 'pagamentos',
                'name' => 'Pagamentos',
                'category' => 'Regularidade',
                'system' => 'Integra Contador',
                'description' => 'Alterações em pagamentos.',
                'default_enabled' => false,
                'is_active' => true,
                'requires_procuracao' => true,
                'procuration_codes' => ['00004'],
            ],
        ]);

        foreach ([
            ['parcsn', 'Parcelamento Simples Nacional'],
            ['parcsn-esp', 'Parcelamento Simples Especial'],
            ['pertsn', 'PERT Simples Nacional'],
            ['relpsn', 'Relp Simples Nacional'],
            ['parcmei', 'Parcelamento MEI'],
            ['parcmei-esp', 'Parcelamento MEI Especial'],
            ['pertmei', 'PERT MEI'],
            ['relpmei', 'Relp MEI'],
        ] as [$id, $name]) {
            $definitions[] = [
                'id' => $id,
                'name' => $name,
                'category' => 'Parcelamentos',
                'system' => 'Integra Contador',
                'description' => 'Pedidos e parcelas.',
                'default_enabled' => false,
                'is_active' => true,
                'requires_procuracao' => true,
            ];
        }

        $definitions[] = [
            'id' => 'parc-paex',
            'name' => 'Parcelamento PAEX',
            'category' => 'Parcelamentos',
            'system' => 'Integra Contador',
            'description' => 'Roadmap.',
            'default_enabled' => false,
            'is_active' => true,
            'requires_procuracao' => true,
            'availability' => MonitoringDefinition::AVAILABILITY_PROSPECCAO,
        ];
        $definitions[] = [
            'id' => 'parc-sipade',
            'name' => 'Parcelamento SIPADE',
            'category' => 'Parcelamentos',
            'system' => 'Integra Contador',
            'description' => 'Roadmap.',
            'default_enabled' => false,
            'is_active' => true,
            'requires_procuracao' => true,
            'availability' => MonitoringDefinition::AVAILABILITY_PROSPECCAO,
        ];

        foreach ($definitions as $definition) {
            $definitionId = (string) $definition['id'];
            $definition['operations'] = ConsultCatalog::operationsFor($definitionId);
            if (! array_key_exists('procuration_codes', $definition) && ($codes = ProcurationCatalog::codesForDefinition($definitionId)) !== null) {
                $definition['procuration_codes'] = $codes;
            }
            if ($personTypes = ConsultCatalog::personTypesFor($definitionId)) {
                $definition['person_types'] = $personTypes;
            }
            if (ConsultCatalog::isProspeccao($definitionId)) {
                $definition['availability'] = MonitoringDefinition::AVAILABILITY_PROSPECCAO;
                $definition['operations'] = null;
            }
            $definition += [
                'version' => MonitoringDefinition::CATALOG_VERSION,
                'availability' => MonitoringDefinition::AVAILABILITY_PRODUCTION,
                'strategy' => MonitoringDefinition::STRATEGY_POLLING,
                'operations' => null,
                'procuration_codes' => null,
                'person_types' => ['PF', 'PJ'],
                'regimes' => null,
                'services' => null,
            ];

            MonitoringDefinition::query()->updateOrCreate(
                ['id' => $definition['id']],
                $definition,
            );
        }
    }
}
