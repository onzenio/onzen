<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Models\SerproContract;
use App\Services\AccountCertificateService;
use App\Services\MonitoringHealthService;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MonitoringHealthTest extends TestCase
{
    use RefreshDatabase;

    private function platform(): \App\Models\Account
    {
        return $this->createAccount(['profile' => AccountProfile::A]);
    }

    private function approvedContractWithSecrets($platform): SerproContract
    {
        $vault = app(VaultService::class);

        return SerproContract::factory()->create([
            'environment' => 'homologacao',
            'transport_approved' => true,
            'consumer_key_ref' => $vault->put($platform, 'consumer-key', 'KEY'),
            'consumer_secret_ref' => $vault->put($platform, 'consumer-secret', 'SEC'),
        ]);
    }

    private function validPlatformCert($platform, bool $expired = false): void
    {
        app(AccountCertificateService::class)->register($platform, [
            'pfx_ref' => 'secret:PFX',
            'password_ref' => 'secret:PWD',
            'holder_name' => 'Operadora',
            'thumbprint' => str_repeat('a', 40),
            'expires_at' => $expired ? now()->subDay() : now()->addYear(),
        ]);
    }

    public function test_gated_quando_transporte_desligado(): void
    {
        $platform = $this->platform();

        $this->assertSame('gated', app(MonitoringHealthService::class)->check($platform)['status']);

        SerproContract::factory()->create(['environment' => 'homologacao', 'transport_approved' => false]);
        $this->assertSame('gated', app(MonitoringHealthService::class)->check($platform)['status']);
    }

    public function test_configured_quando_tudo_pronto(): void
    {
        $platform = $this->platform();
        $this->approvedContractWithSecrets($platform);
        $this->validPlatformCert($platform);

        $health = app(MonitoringHealthService::class)->check($platform);

        $this->assertSame('configured', $health['status']);
        $this->assertNull($health['reason']);
    }

    public function test_unavailable_quando_credencial_nao_resolve(): void
    {
        $platform = $this->platform();
        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'transport_approved' => true,
            'consumer_key_ref' => 'secret:999999:inexistente',
            'consumer_secret_ref' => 'secret:999999:inexistente',
        ]);
        $this->validPlatformCert($platform);

        $health = app(MonitoringHealthService::class)->check($platform);

        $this->assertSame('unavailable', $health['status']);
    }

    public function test_degraded_quando_certificado_da_plataforma_expirado(): void
    {
        $platform = $this->platform();
        $this->approvedContractWithSecrets($platform);
        $this->validPlatformCert($platform, expired: true);

        $this->assertSame('degraded', app(MonitoringHealthService::class)->check($platform)['status']);
    }

    public function test_painel_prevalece_sobre_ambiente(): void
    {
        $platform = $this->platform();
        Config::set('monitoring.environment', 'homologacao');

        $vault = app(VaultService::class);
        SerproContract::factory()->create([
            'environment' => 'producao',
            'transport_approved' => true,
            'consumer_key_ref' => $vault->put($platform, 'consumer-key', 'KEY'),
            'consumer_secret_ref' => $vault->put($platform, 'consumer-secret', 'SEC'),
        ]);
        $this->validPlatformCert($platform);

        $health = app(MonitoringHealthService::class)->check($platform);

        $this->assertSame('producao', $health['environment']);
        $this->assertSame('panel', $health['source']);
        $this->assertSame('configured', $health['status']);
    }
}
