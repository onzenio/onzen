<?php

namespace Tests\Feature;

use App\Contracts\VaultResolver;
use App\Models\SerproContract;
use App\Models\SerproSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringHealthTest extends TestCase
{
    use RefreshDatabase;

    private function panel(array $overrides): SerproSettings
    {
        $settings = SerproSettings::current();
        $settings->update($overrides);

        return $settings->refresh();
    }

    private function contractWithCredential(string $secret = 'consumer-secret'): SerproContract
    {
        $ref = 'secret:contratante-'.uniqid();
        app(VaultResolver::class)->put($ref, (string) json_encode([
            'client_id' => '179024',
            'consumer_secret' => $secret,
            'contratante_doc' => '65.396.736/0001-76',
        ]));

        return SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => $ref,
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/monitoring/health')->assertUnauthorized();
    }

    public function test_default_state_is_gated_for_authenticated_user(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->getJson('/api/monitoring/health')
            ->assertOk()
            ->assertJsonPath('data.state', 'gated')
            ->assertJsonPath('data.environment', 'homologacao')
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.gated', true)
            ->assertJsonPath('data.transport_open', false);
    }

    public function test_panel_approval_with_env_off_reports_configured(): void
    {
        $this->panel(['transport_approved' => true, 'transport_approved_at' => now()]);
        config()->set('monitoring.transport.approved', false);
        config()->set('monitoring.dry_run', true);
        $this->contractWithCredential();

        $this->actingAs($this->createUser())
            ->getJson('/api/monitoring/health')
            ->assertOk()
            ->assertJsonPath('data.state', 'configured')
            ->assertJsonPath('data.environment', 'homologacao')
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.gated', false)
            ->assertJsonPath('data.transport_open', true);
    }

    public function test_missing_credential_reports_unavailable(): void
    {
        config()->set('monitoring.transport.approved', true);
        config()->set('monitoring.dry_run', false);

        $this->actingAs($this->createUser())
            ->getJson('/api/monitoring/health')
            ->assertOk()
            ->assertJsonPath('data.state', 'unavailable')
            ->assertJsonPath('data.gated', false)
            ->assertJsonPath('data.transport_open', true);
    }

    public function test_env_only_approval_reports_degraded(): void
    {
        config()->set('monitoring.transport.approved', true);
        config()->set('monitoring.dry_run', false);
        $this->contractWithCredential();

        $this->actingAs($this->createUser())
            ->getJson('/api/monitoring/health')
            ->assertOk()
            ->assertJsonPath('data.state', 'degraded')
            ->assertJsonPath('data.gated', false)
            ->assertJsonPath('data.transport_open', true);
    }

    public function test_payload_never_contains_secrets_or_vault_refs(): void
    {
        $this->panel(['transport_approved' => true, 'transport_approved_at' => now()]);
        $contract = $this->contractWithCredential('super-secret-consumer-value');

        $response = $this->actingAs($this->createUser())
            ->getJson('/api/monitoring/health')
            ->assertOk();

        $payload = (string) $response->getContent();

        $this->assertStringNotContainsString('super-secret-consumer-value', $payload);
        $this->assertStringNotContainsString((string) $contract->credential_ref, $payload);
        $this->assertStringNotContainsString('credential_ref', $payload);
        $this->assertStringNotContainsString('consumer_secret', $payload);
    }
}
