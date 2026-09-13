<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\SerproContract;
use App\Models\SerproSettings;
use App\Models\User;
use App\Services\Monitoring\SerproTransportGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class AdminSerproTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $accountA = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function accountA(): Account
    {
        return $this->accountA ??= Account::factory()->create(['profile' => AccountProfile::A]);
    }

    private function superAdminA(): User
    {
        return $this->createUser($this->accountA(), ['role' => UserRole::SuperAdmin]);
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

    private function contractWithCredential(
        string $environment = 'homologacao',
        string $clientId = '111111',
        string $secret = 'old-secret',
        ?string $certificate = null,
        ?string $certificatePassword = null,
    ): SerproContract {
        $ref = 'secret:serpro-contratante-'.uniqid();

        app(VaultResolver::class)->put($ref, (string) json_encode(array_filter([
            'client_id' => $clientId,
            'consumer_secret' => $secret,
            'certificate' => $certificate,
            'certificate_password' => $certificatePassword,
        ], fn ($value): bool => $value !== null)));

        return SerproContract::factory()->create([
            'environment' => $environment,
            'credential_ref' => $ref,
        ]);
    }

    public function test_get_requires_super_admin_of_account_a(): void
    {
        $this->getJson('/api/admin/serpro')->assertUnauthorized();

        $accountA = $this->accountA();

        foreach ([UserRole::Admin, UserRole::Operator, UserRole::User] as $role) {
            $this->actingAs($this->createUser($accountA, ['role' => $role]))
                ->getJson('/api/admin/serpro')
                ->assertForbidden();
        }

        $accountB = $this->createAccount();
        $outsider = $this->createUser($accountB, ['role' => UserRole::SuperAdmin]);

        $this->actingAs($outsider)->getJson('/api/admin/serpro')->assertForbidden();

        $accountASuperAdmin = $this->superAdminA();

        $this->actingAs($accountASuperAdmin)->getJson('/api/admin/serpro')->assertOk();

        $this->actingAs($accountASuperAdmin)
            ->withSession(['switch_account_id' => $accountB->id])
            ->getJson('/api/admin/serpro')
            ->assertForbidden();
    }

    public function test_super_admin_acting_in_account_a_context_can_manage(): void
    {
        $outsider = $this->createUser($this->createAccount(), ['role' => UserRole::SuperAdmin]);

        $this->actingAs($outsider)
            ->withSession(['switch_account_id' => $this->accountA()->id])
            ->getJson('/api/admin/serpro')
            ->assertOk();
    }

    public function test_mutations_are_forbidden_for_everyone_but_account_a_super_admin(): void
    {
        $accountA = $this->accountA();

        $postRoutes = [
            '/api/admin/serpro/credentials',
            '/api/admin/serpro/environment',
            '/api/admin/serpro/transport',
        ];

        foreach ([UserRole::Admin, UserRole::Operator, UserRole::User] as $role) {
            $actor = $this->createUser($accountA, ['role' => $role]);

            foreach ($postRoutes as $route) {
                $this->actingAs($actor)->postJson($route, [])->assertForbidden();
            }
        }

        $outsider = $this->createUser($this->createAccount(), ['role' => UserRole::SuperAdmin]);

        foreach ($postRoutes as $route) {
            $this->actingAs($outsider)->postJson($route, [])->assertForbidden();
        }

        $this->assertSame(0, SerproSettings::query()->count());
        $this->assertSame(0, SerproContract::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_get_reports_factual_fail_closed_state_without_creating_the_panel_row(): void
    {
        $this->actingAs($this->superAdminA())
            ->getJson('/api/admin/serpro')
            ->assertOk()
            ->assertJsonPath('data.environment', 'homologacao')
            ->assertJsonPath('data.transport.state', SerproTransportGate::STATE_GATED)
            ->assertJsonPath('data.transport.open', false)
            ->assertJsonPath('data.transport.dry_run', true)
            ->assertJsonPath('data.credential_version', 1)
            ->assertJsonPath('data.credentials.homologacao.present', false)
            ->assertJsonPath('data.credentials.homologacao.resolved', false)
            ->assertJsonPath('data.credentials.homologacao.identifier', null)
            ->assertJsonPath('data.credentials.homologacao.has_certificate', false)
            ->assertJsonPath('data.credentials.producao.present', false);

        $this->assertSame(0, SerproSettings::query()->count());
    }

    public function test_get_reports_effective_transport_state_and_masked_identifier(): void
    {
        $this->panel(['transport_approved' => true, 'transport_approved_at' => now()]);
        $this->contractWithCredential('homologacao', clientId: '179024', certificate: 'pfx-bytes', certificatePassword: 'pfx-password');

        $this->actingAs($this->superAdminA())
            ->getJson('/api/admin/serpro')
            ->assertOk()
            ->assertJsonPath('data.environment', 'homologacao')
            ->assertJsonPath('data.transport.state', SerproTransportGate::STATE_CONFIGURED)
            ->assertJsonPath('data.transport.open', true)
            ->assertJsonPath('data.credentials.homologacao.present', true)
            ->assertJsonPath('data.credentials.homologacao.resolved', true)
            ->assertJsonPath('data.credentials.homologacao.identifier', '**9024')
            ->assertJsonPath('data.credentials.homologacao.has_certificate', true)
            ->assertJsonPath('data.credentials.homologacao.version', 1);
    }

    public function test_get_payload_never_contains_secret_certificate_password_or_vault_ref(): void
    {
        $this->panel(['transport_approved' => true, 'transport_approved_at' => now()]);
        $contract = $this->contractWithCredential(
            'homologacao',
            clientId: '179024',
            secret: 'super-secret-consumer-value',
            certificate: base64_encode('pfx-raw-bytes'),
            certificatePassword: 'pfx-password',
        );

        $response = $this->actingAs($this->superAdminA())->getJson('/api/admin/serpro')->assertOk();
        $payload = (string) $response->getContent();

        foreach ([
            'super-secret-consumer-value',
            base64_encode('pfx-raw-bytes'),
            'pfx-password',
            '179024',
            (string) $contract->credential_ref,
            'consumer_secret',
            'certificate_password',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $payload);
        }
    }

    public function test_super_admin_replaces_credentials_with_masked_response_audit_and_old_ref_purge(): void
    {
        $admin = $this->superAdminA();
        $old = $this->contractWithCredential('homologacao', clientId: '111111', secret: 'old-secret');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/serpro/credentials', [
                'environment' => 'homologacao',
                'client_id' => '179024',
                'consumer_secret' => 'new-super-secret',
                'contratante_doc' => '65.396.736/0001-76',
                'certificate' => base64_encode('pfx-raw-bytes'),
                'certificate_password' => 'pfx-password',
            ])
            ->assertCreated()
            ->assertJsonPath('data.credentials.homologacao.present', true)
            ->assertJsonPath('data.credentials.homologacao.resolved', true)
            ->assertJsonPath('data.credentials.homologacao.identifier', '**9024')
            ->assertJsonPath('data.credentials.homologacao.has_certificate', true);

        $payload = (string) $response->getContent();

        foreach (['new-super-secret', base64_encode('pfx-raw-bytes'), 'pfx-password', '179024', 'consumer_secret'] as $needle) {
            $this->assertStringNotContainsString($needle, $payload);
        }

        $contract = SerproContract::query()->where('environment', 'homologacao')->firstOrFail();
        $this->assertNotSame($old->credential_ref, $contract->credential_ref);
        $this->assertSame($admin->id, $contract->updated_by_user_id);

        $vault = app(VaultResolver::class);
        $this->assertNull($vault->get((string) $old->credential_ref));

        $credentials = app(SerproCredentialResolver::class)->resolve($contract);
        $this->assertSame('179024', $credentials->eCnpj);
        $this->assertSame('new-super-secret', $credentials->consumerSecret);
        $this->assertSame(base64_encode('pfx-raw-bytes'), $credentials->certificate);
        $this->assertSame('pfx-password', $credentials->certificatePassword);

        $audit = AuditLog::query()->where('action', 'platform.serpro_credentials_rotated')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame($this->accountA()->id, $audit->origin_account_id);
        $this->assertSame('**9024', $audit->metadata['identifier']);
        $this->assertSame('homologacao', $audit->metadata['environment']);
        $this->assertNotNull($audit->created_at);

        $metadata = (string) json_encode($audit->metadata);

        foreach (['new-super-secret', base64_encode('pfx-raw-bytes'), 'pfx-password'] as $needle) {
            $this->assertStringNotContainsString($needle, $metadata);
        }
    }

    public function test_credential_replacement_does_not_serve_a_stale_oauth_token(): void
    {
        $admin = $this->superAdminA();
        $contract = $this->contractWithCredential('homologacao', clientId: '111111', secret: 'old-secret');

        $transport = new FakeSerproTransport([
            ['access_token' => 'stale-token', 'expires_in' => 300],
            ['access_token' => 'fresh-token', 'expires_in' => 300],
        ]);
        $this->app->instance(SerproTransport::class, $transport);

        $cache = app(OAuthTokenCache::class);
        $resolver = app(SerproCredentialResolver::class);

        $first = $cache->get($resolver->resolve($contract), 'homologacao', (string) $contract->credential_ref);
        $this->assertSame('stale-token', $first['access_token']);
        $this->assertCount(1, $transport->tokenCalls);

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/credentials', [
                'environment' => 'homologacao',
                'client_id' => '179024',
                'consumer_secret' => 'new-secret',
            ])
            ->assertCreated();

        $rotated = $contract->refresh();
        $second = $cache->get($resolver->resolve($rotated), 'homologacao', (string) $rotated->credential_ref);

        $this->assertSame('fresh-token', $second['access_token']);
        $this->assertCount(2, $transport->tokenCalls);
    }

    public function test_environment_switch_to_production_without_double_confirmation_and_evidence_is_refused(): void
    {
        $admin = $this->superAdminA();

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/environment', ['environment' => 'producao'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_environment', 'confirm_impact', 'evidence']);

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/environment', [
                'environment' => 'producao',
                'confirm_environment' => true,
                'evidence' => 'CHG-1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_impact']);

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/environment', [
                'environment' => 'producao',
                'confirm_environment' => true,
                'confirm_impact' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['evidence']);

        $this->assertSame('homologacao', app(SerproTransportGate::class)->environment());
        $this->assertSame(0, SerproSettings::query()->count());

        $refusals = AuditLog::query()
            ->where('action', 'platform.serpro_environment_switch_refused')
            ->get();

        $this->assertCount(3, $refusals);
        $this->assertSame($admin->id, $refusals->first()->actor_user_id);
        $this->assertSame('producao', $refusals->first()->metadata['requested_environment']);
    }

    public function test_environment_switch_to_production_with_double_confirmation_and_evidence_succeeds(): void
    {
        $admin = $this->superAdminA();

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/environment', [
                'environment' => 'producao',
                'confirm_environment' => true,
                'confirm_impact' => true,
                'evidence' => 'CHG-1234: homologação validada',
            ])
            ->assertOk()
            ->assertJsonPath('data.environment', 'producao');

        $settings = SerproSettings::query()->firstOrFail();
        $this->assertSame('producao', $settings->environment());

        $audit = AuditLog::query()->where('action', 'platform.serpro_environment_switched')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('homologacao', $audit->metadata['before']);
        $this->assertSame('producao', $audit->metadata['after']);
        $this->assertSame('CHG-1234: homologação validada', $audit->metadata['evidence']);
    }

    public function test_environment_switch_back_to_homologacao_needs_no_ceremony(): void
    {
        $admin = $this->superAdminA();
        SerproSettings::factory()->create(['environment' => 'producao', 'transport_approved' => true]);

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/environment', ['environment' => 'homologacao'])
            ->assertOk()
            ->assertJsonPath('data.environment', 'homologacao');

        $this->assertSame('homologacao', SerproSettings::query()->firstOrFail()->environment());
    }

    public function test_transport_can_be_enabled_in_homologacao_without_production_ceremony(): void
    {
        $admin = $this->superAdminA();

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/transport', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.transport.open', true)
            ->assertJsonPath('data.transport.state', SerproTransportGate::STATE_UNAVAILABLE);

        $settings = SerproSettings::query()->firstOrFail();
        $this->assertTrue($settings->transportApproved());
        $this->assertSame($admin->id, $settings->transport_approved_by_user_id);
        $this->assertNotNull($settings->transport_approved_at);

        $audit = AuditLog::query()->where('action', 'platform.serpro_transport_toggled')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertFalse($audit->metadata['before']);
        $this->assertTrue($audit->metadata['after']);
        $this->assertSame('homologacao', $audit->metadata['environment']);
    }

    public function test_enabling_transport_in_production_without_double_confirmation_and_evidence_is_refused(): void
    {
        $admin = $this->superAdminA();
        SerproSettings::factory()->create(['environment' => 'producao', 'transport_approved' => false]);

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/transport', ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_transport', 'confirm_impact', 'evidence']);

        $this->assertFalse(SerproSettings::query()->firstOrFail()->transportApproved());

        $refusals = AuditLog::query()
            ->where('action', 'platform.serpro_transport_refused')
            ->get();

        $this->assertCount(1, $refusals);
        $this->assertSame('producao', $refusals->first()->metadata['environment']);

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/transport', [
                'enabled' => true,
                'confirm_transport' => true,
                'confirm_impact' => true,
                'evidence' => 'CHG-99: homologação validada',
            ])
            ->assertOk()
            ->assertJsonPath('data.transport.open', true);

        $settings = SerproSettings::query()->firstOrFail();
        $this->assertTrue($settings->transportApproved());
        $this->assertSame($admin->id, $settings->transport_approved_by_user_id);

        $audit = AuditLog::query()->where('action', 'platform.serpro_transport_toggled')->firstOrFail();
        $this->assertTrue($audit->metadata['after']);
        $this->assertSame('CHG-99: homologação validada', $audit->metadata['evidence']);
        $this->assertSame('producao', $audit->metadata['environment']);
    }

    public function test_disabling_transport_is_immediate_and_needs_no_ceremony(): void
    {
        $admin = $this->superAdminA();
        SerproSettings::factory()->create([
            'environment' => 'producao',
            'transport_approved' => true,
            'transport_approved_at' => now(),
            'transport_approved_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/serpro/transport', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.transport.open', false)
            ->assertJsonPath('data.transport.state', SerproTransportGate::STATE_GATED);

        $settings = SerproSettings::query()->firstOrFail();
        $this->assertFalse($settings->transportApproved());
        $this->assertNull($settings->transport_approved_at);
        $this->assertNull($settings->transport_approved_by_user_id);

        $audit = AuditLog::query()->where('action', 'platform.serpro_transport_toggled')->firstOrFail();
        $this->assertTrue($audit->metadata['before']);
        $this->assertFalse($audit->metadata['after']);
    }
}
