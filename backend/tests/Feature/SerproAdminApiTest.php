<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Models\SerproContract;
use App\Models\User;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $platform = $this->createAccount(['profile' => AccountProfile::A]);

        return $this->createUser($platform, ['role' => UserRole::SuperAdmin]);
    }

    public function test_guest_401_e_nao_super_admin_403(): void
    {
        $this->getJson('/api/admin/serpro')->assertUnauthorized();

        foreach ([UserRole::Admin, UserRole::Operator, UserRole::User] as $role) {
            $account = $this->createAccount(['profile' => AccountProfile::B]);
            $user = $this->createUser($account, ['role' => $role]);

            $this->actingAs($user)->getJson('/api/admin/serpro')->assertForbidden();
            $this->actingAs($user)->putJson('/api/admin/serpro/credentials', [
                'consumer_key' => 'K', 'consumer_secret' => 'S',
            ])->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/serpro/environment', [
                'environment' => 'producao', 'confirmed' => true, 'evidence' => 'x',
            ])->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/serpro/transport', [
                'approved' => true, 'confirmed' => true,
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('serpro_contracts', 0);
    }

    public function test_show_exibe_mascarado_sem_segredo(): void
    {
        $admin = $this->superAdmin();
        $vault = app(VaultService::class);

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'consumer_key_ref' => $vault->put($admin->account, 'consumer-key', 'CHAVE-REAL'),
            'consumer_secret_ref' => $vault->put($admin->account, 'consumer-secret', 'SEGREDO-REAL'),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/serpro')->assertOk();

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('CHAVE-REAL', $body);
        $this->assertStringNotContainsString('SEGREDO-REAL', $body);
        $response->assertJsonPath('environment', 'homologacao')
            ->assertJsonStructure(['consumer_key_masked', 'consumer_secret_masked', 'health']);
    }

    public function test_troca_de_credenciais_e_auditada(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/api/admin/serpro/credentials', [
            'consumer_key' => 'NOVA-CHAVE',
            'consumer_secret' => 'NOVO-SEGREDO',
        ])->assertOk()->assertJsonPath('environment', 'homologacao');

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'serpro_contract.credentials_rotated',
        ]);

        // Nova leitura continua mascarada.
        $body = (string) $this->actingAs($admin)->getJson('/api/admin/serpro')->getContent();
        $this->assertStringNotContainsString('NOVA-CHAVE', $body);
        $this->assertStringNotContainsString('NOVO-SEGREDO', $body);
    }

    public function test_producao_exige_dupla_confirmacao_com_evidencia(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/admin/serpro/environment', [
            'environment' => 'producao', 'confirmed' => true,
        ])->assertStatus(422);

        $this->assertSame('homologacao', SerproContract::query()->firstOrFail()->environment);
        $this->assertDatabaseHas('audit_logs', ['action' => 'serpro_contract.environment_switch_refused']);

        $this->actingAs($admin)->postJson('/api/admin/serpro/environment', [
            'environment' => 'producao', 'confirmed' => true, 'evidence' => 'Contrato SERPRO nº 123, homologação OK.',
        ])->assertOk()->assertJsonPath('environment', 'producao');

        $this->assertDatabaseHas('audit_logs', ['action' => 'serpro_contract.environment_switched']);
    }

    public function test_desligar_transporte_e_imediato_e_religar_em_producao_exige_evidencia(): void
    {
        $admin = $this->superAdmin();
        SerproContract::factory()->create(['environment' => 'producao', 'transport_approved' => true]);

        // Desligar: imediato, sem evidência.
        $this->actingAs($admin)->postJson('/api/admin/serpro/transport', [
            'approved' => false, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('transport_approved', false);

        $this->assertFalse(SerproContract::query()->firstOrFail()->transport_approved);

        // Religar em produção sem evidência: recusado, permanece desligado.
        $this->actingAs($admin)->postJson('/api/admin/serpro/transport', [
            'approved' => true, 'confirmed' => true,
        ])->assertStatus(422);

        $this->assertFalse(SerproContract::query()->firstOrFail()->transport_approved);
        $this->assertDatabaseHas('audit_logs', ['action' => 'serpro_transport.enable_refused']);

        // Com evidência: religa.
        $this->actingAs($admin)->postJson('/api/admin/serpro/transport', [
            'approved' => true, 'confirmed' => true, 'evidence' => 'Janela de produção aprovada.',
        ])->assertOk()->assertJsonPath('transport_approved', true);
    }
}
