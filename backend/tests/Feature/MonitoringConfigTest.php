<?php

namespace Tests\Feature;

use Tests\TestCase;

class MonitoringConfigTest extends TestCase
{
    public function test_defaults_sao_homologacao_dry_run_e_transporte_desligado(): void
    {
        $this->assertSame('homologacao', config('monitoring.environment'));
        $this->assertTrue((bool) config('monitoring.dry_run'));
        $this->assertFalse((bool) config('monitoring.transport.approved'));
        $this->assertSame('serpro', config('monitoring.queue'));
    }

    public function test_overrides_por_variavel_de_ambiente(): void
    {
        putenv('MONITORING_SERPRO_ENVIRONMENT=producao');
        putenv('MONITORING_SERPRO_DRY_RUN=false');
        putenv('MONITORING_SERPRO_TRANSPORT_APPROVED=true');

        try {
            $config = require base_path('config/monitoring.php');

            $this->assertSame('producao', $config['environment']);
            $this->assertFalse((bool) $config['dry_run']);
            $this->assertTrue((bool) $config['transport']['approved']);
        } finally {
            putenv('MONITORING_SERPRO_ENVIRONMENT');
            putenv('MONITORING_SERPRO_DRY_RUN');
            putenv('MONITORING_SERPRO_TRANSPORT_APPROVED');
        }
    }

    public function test_env_example_documenta_variaveis(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        foreach (['MONITORING_SERPRO_ENVIRONMENT', 'MONITORING_SERPRO_DRY_RUN', 'MONITORING_SERPRO_TRANSPORT_APPROVED', 'MONITORING_SERPRO_QUEUE'] as $key) {
            $this->assertStringContainsString($key, $example);
        }
    }
}
