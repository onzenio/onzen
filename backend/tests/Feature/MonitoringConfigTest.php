<?php

namespace Tests\Feature;

use Tests\TestCase;

class MonitoringConfigTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const ENV_KEYS = [
        'MONITORING_SERPRO_BASE_URL',
        'MONITORING_SERPRO_TOKEN_URL',
        'MONITORING_SERPRO_ENVIRONMENT',
        'MONITORING_SERPRO_DRY_RUN',
        'MONITORING_SERPRO_TRANSPORT_APPROVED',
        'MONITORING_SERPRO_TIMEOUT',
        'MONITORING_SERPRO_CONNECT_TIMEOUT',
        'MONITORING_SERPRO_ROLE_TYPE',
        'MONITORING_SERPRO_QUEUE',
        'MONITORING_SERPRO_QUEUE_CONNECTION',
        'MONITORING_SERPRO_FIXTURES_PATH',
        'MONITORING_SERPRO_MAX_ATTEMPTS',
    ];

    protected function tearDown(): void
    {
        $this->clearMonitoringEnv();

        parent::tearDown();
    }

    public function test_defaults_are_fail_closed(): void
    {
        $this->loadMonitoringConfig();

        $this->assertSame('https://gateway.apiserpro.serpro.gov.br/integra-contador/v1', config('monitoring.base_url'));
        $this->assertSame('https://autenticacao.sapi.serpro.gov.br/authenticate', config('monitoring.token_url'));
        $this->assertSame('homologacao', config('monitoring.environment'));
        $this->assertTrue(config('monitoring.dry_run'));
        $this->assertFalse(config('monitoring.transport.approved'));
        $this->assertSame(15, config('monitoring.transport.timeout'));
        $this->assertSame(5, config('monitoring.transport.connect_timeout'));
        $this->assertSame('TERCEIROS', config('monitoring.transport.role_type'));
        $this->assertSame('serpro', config('monitoring.queue'));
        $this->assertSame('serpro', config('monitoring.queue_connection'));
        $this->assertSame(8, config('monitoring.limits.max_attempts'));
    }

    public function test_fixtures_path_defaults_to_backend_consult_fixtures_directory(): void
    {
        $this->loadMonitoringConfig();

        $this->assertSame('resources/fixtures/serpro/consultar', config('monitoring.fixtures_path'));
        $this->assertSame(
            base_path('resources/fixtures/serpro/consultar'),
            base_path((string) config('monitoring.fixtures_path')),
        );
        $this->assertStringEndsWith(
            'backend/resources/fixtures/serpro/consultar',
            base_path((string) config('monitoring.fixtures_path')),
        );
    }

    public function test_every_key_can_be_overridden_by_environment(): void
    {
        $this->loadMonitoringConfig([
            'MONITORING_SERPRO_BASE_URL' => 'https://sandbox.example.test/v2',
            'MONITORING_SERPRO_TOKEN_URL' => 'https://sandbox.example.test/token',
            'MONITORING_SERPRO_ENVIRONMENT' => 'producao',
            'MONITORING_SERPRO_DRY_RUN' => 'false',
            'MONITORING_SERPRO_TRANSPORT_APPROVED' => 'true',
            'MONITORING_SERPRO_TIMEOUT' => '30',
            'MONITORING_SERPRO_CONNECT_TIMEOUT' => '10',
            'MONITORING_SERPRO_ROLE_TYPE' => 'PROPRIO',
            'MONITORING_SERPRO_QUEUE' => 'serpro-high',
            'MONITORING_SERPRO_QUEUE_CONNECTION' => 'redis',
            'MONITORING_SERPRO_FIXTURES_PATH' => 'resources/fixtures/serpro/consultar/custom',
            'MONITORING_SERPRO_MAX_ATTEMPTS' => '3',
        ]);

        $this->assertSame('https://sandbox.example.test/v2', config('monitoring.base_url'));
        $this->assertSame('https://sandbox.example.test/token', config('monitoring.token_url'));
        $this->assertSame('producao', config('monitoring.environment'));
        $this->assertFalse(config('monitoring.dry_run'));
        $this->assertTrue(config('monitoring.transport.approved'));
        $this->assertSame(30, config('monitoring.transport.timeout'));
        $this->assertSame(10, config('monitoring.transport.connect_timeout'));
        $this->assertSame('PROPRIO', config('monitoring.transport.role_type'));
        $this->assertSame('serpro-high', config('monitoring.queue'));
        $this->assertSame('redis', config('monitoring.queue_connection'));
        $this->assertSame('resources/fixtures/serpro/consultar/custom', config('monitoring.fixtures_path'));
        $this->assertSame(3, config('monitoring.limits.max_attempts'));
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function loadMonitoringConfig(array $overrides = []): void
    {
        $this->clearMonitoringEnv();

        foreach ($overrides as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        config()->set('monitoring', require config_path('monitoring.php'));
    }

    private function clearMonitoringEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}
