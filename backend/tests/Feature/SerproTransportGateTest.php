<?php

namespace Tests\Feature;

use App\Contracts\VaultResolver;
use App\Models\SerproContract;
use App\Models\SerproSettings;
use App\Services\Monitoring\SerproTransportGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproTransportGateTest extends TestCase
{
    use RefreshDatabase;

    private VaultResolver $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = app(VaultResolver::class);
    }

    private function gate(): SerproTransportGate
    {
        return app(SerproTransportGate::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function panel(array $overrides): SerproSettings
    {
        $settings = SerproSettings::current();
        $settings->update($overrides);

        return $settings->refresh();
    }

    private function contractWithCredential(string $environment = 'homologacao'): SerproContract
    {
        $ref = 'secret:contratante-'.uniqid();
        $this->vault->put($ref, (string) json_encode([
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => '65.396.736/0001-76',
        ]));

        return SerproContract::factory()->create([
            'environment' => $environment,
            'credential_ref' => $ref,
        ]);
    }

    private function openEnvFallback(): void
    {
        config()->set('monitoring.transport.approved', true);
        config()->set('monitoring.dry_run', false);
    }

    public function test_defaults_are_fail_closed(): void
    {
        $gate = $this->gate();

        $this->assertSame('homologacao', $gate->environment());
        $this->assertSame('homologacao', config('monitoring.environment'));
        $this->assertTrue($gate->isDryRun());
        $this->assertFalse($gate->isTransportOpen());
        $this->assertTrue($gate->isGated());
        $this->assertNull($gate->effectiveCredentialRef());
        $this->assertSame(SerproTransportGate::STATE_GATED, $gate->transportState());
    }

    public function test_panel_environment_wins_over_environment_config(): void
    {
        $this->panel(['environment' => 'producao']);
        config()->set('monitoring.environment', 'homologacao');

        $this->assertSame('producao', $this->gate()->environment());
    }

    public function test_invalid_environment_falls_back_to_config_then_to_homologacao(): void
    {
        $this->panel(['environment' => 'sandbox']);
        config()->set('monitoring.environment', 'producao');
        $this->assertSame('producao', $this->gate()->environment());

        config()->set('monitoring.environment', 'qualquer-coisa');
        $this->assertSame('homologacao', $this->gate()->environment());
    }

    public function test_panel_approval_opens_transport_even_with_env_off_and_dry_run_on(): void
    {
        $this->panel(['transport_approved' => true, 'transport_approved_at' => now()]);
        config()->set('monitoring.transport.approved', false);
        config()->set('monitoring.dry_run', true);

        $gate = $this->gate();

        $this->assertTrue($gate->isTransportOpen());
        $this->assertFalse($gate->isGated());
    }

    public function test_env_fallback_opens_only_without_panel_approval_and_with_dry_run_off(): void
    {
        config()->set('monitoring.transport.approved', true);
        config()->set('monitoring.dry_run', true);

        $gate = $this->gate();
        $this->assertFalse($gate->isTransportOpen());
        $this->assertTrue($gate->isGated());

        config()->set('monitoring.dry_run', false);

        $this->assertTrue($gate->isTransportOpen());
        $this->assertFalse($gate->isGated());
    }

    public function test_explicit_panel_rejection_closes_transport_even_with_env_fallback_on(): void
    {
        $this->panel(['transport_approved' => false]);
        $this->openEnvFallback();

        $gate = $this->gate();

        $this->assertFalse($gate->isTransportOpen());
        $this->assertTrue($gate->isGated());
        $this->assertSame(SerproTransportGate::STATE_GATED, $gate->transportState());
    }

    public function test_gate_reads_do_not_create_the_panel_singleton(): void
    {
        $this->openEnvFallback();
        $this->contractWithCredential();

        $gate = $this->gate();
        $gate->environment();
        $gate->isTransportOpen();
        $gate->transportState();

        $this->assertSame(0, SerproSettings::query()->count());
        $this->assertSame(SerproTransportGate::STATE_DEGRADED, $gate->transportState());
    }

    public function test_missing_credential_is_unavailable_when_transport_is_open(): void
    {
        $this->openEnvFallback();

        $gate = $this->gate();
        $this->assertNull($gate->effectiveCredentialRef());
        $this->assertSame(SerproTransportGate::STATE_UNAVAILABLE, $gate->transportState());

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => null,
        ]);

        $this->assertNull($gate->effectiveCredentialRef());
        $this->assertSame(SerproTransportGate::STATE_UNAVAILABLE, $gate->transportState());
    }

    public function test_unresolvable_credential_is_unavailable(): void
    {
        $this->openEnvFallback();

        $contract = SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => 'secret:contratante-missing',
        ]);

        $gate = $this->gate();
        $this->assertSame('secret:contratante-missing', $gate->effectiveCredentialRef());
        $this->assertSame(SerproTransportGate::STATE_UNAVAILABLE, $gate->transportState());

        $contract->update(['credential_ref' => 'not-an-opaque-ref']);
        $this->assertSame(SerproTransportGate::STATE_UNAVAILABLE, $gate->transportState());

        $this->vault->put('secret:contratante-invalid', '{"client_id":"179024"}');
        $contract->update(['credential_ref' => 'secret:contratante-invalid']);
        $this->assertSame(SerproTransportGate::STATE_UNAVAILABLE, $gate->transportState());
    }

    public function test_panel_approval_with_resolved_credential_is_configured(): void
    {
        $this->panel(['transport_approved' => true, 'transport_approved_at' => now()]);
        config()->set('monitoring.transport.approved', false);
        config()->set('monitoring.dry_run', true);
        $this->contractWithCredential();

        $gate = $this->gate();

        $this->assertTrue($gate->isTransportOpen());
        $this->assertFalse($gate->isGated());
        $this->assertSame(SerproTransportGate::STATE_CONFIGURED, $gate->transportState());
    }

    public function test_env_only_approval_with_resolved_credential_is_degraded(): void
    {
        $this->openEnvFallback();
        $this->contractWithCredential();

        $gate = $this->gate();

        $this->assertSame(SerproTransportGate::STATE_DEGRADED, $gate->transportState());
    }

    public function test_env_only_approval_without_credential_is_unavailable_not_degraded(): void
    {
        $this->openEnvFallback();

        $this->assertSame(SerproTransportGate::STATE_UNAVAILABLE, $this->gate()->transportState());
    }

    public function test_effective_credential_ref_follows_the_effective_environment(): void
    {
        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => 'secret:contratante-homolog',
        ]);
        SerproContract::factory()->create([
            'environment' => 'producao',
            'credential_ref' => 'secret:contratante-prod',
        ]);

        $gate = $this->gate();
        $this->assertSame('secret:contratante-homolog', $gate->effectiveCredentialRef());

        $this->panel(['environment' => 'producao']);
        $this->assertSame('secret:contratante-prod', $gate->effectiveCredentialRef());
    }
}
