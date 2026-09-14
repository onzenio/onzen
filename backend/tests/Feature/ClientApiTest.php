<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{cnpj: string, razao_social: string, regime: string, contador_responsavel: string}
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'cnpj' => $this->validCnpj('112223330001'),
            'razao_social' => 'Empresa Exemplo LTDA',
            'regime' => 'simples',
            'contador_responsavel' => 'Contador Exemplo',
        ], $overrides);
    }

    protected function validCnpj(string $seed): string
    {
        $base = substr(preg_replace('/\D/', '', $seed) ?? '', 0, 12);
        $base = str_pad($base, 12, '0', STR_PAD_LEFT);
        $first = self::cnpjDigit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $second = self::cnpjDigit($base.$first, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $base.$first.$second;
    }

    /**
     * @param  array<int, int>  $weights
     */
    protected static function cnpjDigit(string $digits, array $weights): string
    {
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += ((int) $digits[$i]) * $weight;
        }

        $rest = $sum % 11;

        return (string) ($rest < 2 ? 0 : 11 - $rest);
    }

    protected function accountWithPlan(int $maxClients = 10)
    {
        return $this->createAccount([
            'plan_id' => Plan::factory()->create(['max_clients' => $maxClients])->id,
        ]);
    }

    public function test_admin_creates_client_with_valid_cnpj(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->postJson('/api/clients', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('cnpj', $this->validCnpj('112223330001'))
            ->assertJsonPath('monitoring_enabled', false);

        $this->assertDatabaseHas('clients', [
            'account_id' => $account->id,
            'cnpj' => $this->validCnpj('112223330001'),
            'monitoring_enabled' => false,
        ]);
    }

    public function test_client_creation_ignores_a_forged_account_id(): void
    {
        $account = $this->accountWithPlan();
        $other = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson('/api/clients', $this->payload(['account_id' => $other->id]))
            ->assertCreated()
            ->assertJsonPath('account_id', $account->id);

        $this->assertDatabaseMissing('clients', ['account_id' => $other->id]);
    }

    public function test_create_rejects_invalid_data_with_per_field_errors(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)->postJson('/api/clients', $this->payload(['cnpj' => '123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cnpj']);

        $this->actingAs($admin)->postJson('/api/clients', $this->payload(['cnpj' => '11222333000182']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cnpj']);

        $this->actingAs($admin)->postJson('/api/clients', $this->payload(['razao_social' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['razao_social']);

        $this->actingAs($admin)->postJson('/api/clients', [
            'cnpj' => $this->validCnpj('223334440001'),
        ])->assertStatus(422)->assertJsonValidationErrors(['razao_social', 'regime', 'contador_responsavel']);
    }

    public function test_duplicate_cnpj_in_same_account_is_rejected(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $cnpj = $this->validCnpj('112223330001');

        $this->actingAs($admin)->postJson('/api/clients', $this->payload(['cnpj' => $cnpj]))->assertCreated();

        $this->actingAs($admin)->postJson('/api/clients', $this->payload(['cnpj' => $cnpj]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cnpj']);
    }

    public function test_same_cnpj_in_another_account_is_allowed(): void
    {
        $first = $this->accountWithPlan();
        $second = $this->accountWithPlan();
        $adminA = $this->createUser($first, ['role' => UserRole::Admin]);
        $adminB = $this->createUser($second, ['role' => UserRole::Admin]);
        $cnpj = $this->validCnpj('112223330001');

        $this->actingAs($adminA)->postJson('/api/clients', $this->payload(['cnpj' => $cnpj]))->assertCreated();

        $this->actingAs($adminB)->postJson('/api/clients', $this->payload(['cnpj' => $cnpj]))->assertCreated();

        $this->assertDatabaseHas('clients', ['account_id' => $first->id, 'cnpj' => $cnpj]);
        $this->assertDatabaseHas('clients', ['account_id' => $second->id, 'cnpj' => $cnpj]);
    }

    public function test_search_and_regime_filter_scope_to_effective_account(): void
    {
        $account = $this->accountWithPlan(50);
        $other = $this->accountWithPlan(50);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        Client::factory()->for($account, 'account')->create([
            'cnpj' => $this->validCnpj('111111110001'),
            'razao_social' => 'Padaria Pão Quente LTDA',
            'regime' => 'simples',
        ]);
        Client::factory()->for($account, 'account')->create([
            'cnpj' => $this->validCnpj('222222220001'),
            'razao_social' => 'Metalúrgica Ferro Forte SA',
            'regime' => 'presumido',
        ]);
        Client::factory()->for($other, 'account')->create([
            'cnpj' => $this->validCnpj('333333330001'),
            'razao_social' => 'Padaria Estrangeira LTDA',
            'regime' => 'simples',
        ]);

        $this->actingAs($admin)->getJson('/api/clients?search=Padaria')
            ->assertOk()
            ->assertJsonFragment(['razao_social' => 'Padaria Pão Quente LTDA'])
            ->assertJsonMissing(['razao_social' => 'Padaria Estrangeira LTDA']);

        $this->actingAs($admin)->getJson('/api/clients?search='.$this->validCnpj('222222220001'))
            ->assertOk()
            ->assertJsonFragment(['razao_social' => 'Metalúrgica Ferro Forte SA'])
            ->assertJsonMissing(['razao_social' => 'Padaria Pão Quente LTDA']);

        $this->actingAs($admin)->getJson('/api/clients?regime=presumido')
            ->assertOk()
            ->assertJsonFragment(['razao_social' => 'Metalúrgica Ferro Forte SA'])
            ->assertJsonMissing(['razao_social' => 'Padaria Pão Quente LTDA']);
    }

    public function test_index_is_paginated_with_meta(): void
    {
        $account = $this->accountWithPlan(50);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        Client::factory()->for($account, 'account')->count(5)->create();

        $this->actingAs($admin)->getJson('/api/clients?per_page=2')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_updates_client(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create([
            'cnpj' => $this->validCnpj('112223330001'),
        ]);

        $this->actingAs($admin)->patchJson("/api/clients/{$client->id}", [
            'razao_social' => 'Nova Razão Social LTDA',
        ])->assertOk()->assertJsonPath('razao_social', 'Nova Razão Social LTDA');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'razao_social' => 'Nova Razão Social LTDA']);
    }

    public function test_update_rejects_duplicate_cnpj_within_account(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        Client::factory()->for($account, 'account')->create(['cnpj' => $this->validCnpj('112223330001')]);
        $client = Client::factory()->for($account, 'account')->create(['cnpj' => $this->validCnpj('223334440001')]);

        $this->actingAs($admin)->patchJson("/api/clients/{$client->id}", [
            'cnpj' => $this->validCnpj('112223330001'),
        ])->assertStatus(422)->assertJsonValidationErrors(['cnpj']);
    }

    public function test_admin_deletes_client(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();

        $this->actingAs($admin)->deleteJson("/api/clients/{$client->id}")->assertNoContent();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_cross_account_access_yields_not_found_without_leak(): void
    {
        $mine = $this->accountWithPlan();
        $other = $this->accountWithPlan();
        $admin = $this->createUser($mine, ['role' => UserRole::Admin]);
        $foreign = Client::factory()->for($other, 'account')->create();

        $this->actingAs($admin)->getJson("/api/clients/{$foreign->id}")->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/clients/{$foreign->id}", [
            'razao_social' => 'Tentativa',
        ])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/clients/{$foreign->id}")->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/clients/{$foreign->id}/monitoring", [
            'monitoring_enabled' => true,
        ])->assertNotFound();
    }

    public function test_client_limit_overflow_returns_422_with_upgrade_message(): void
    {
        $account = $this->accountWithPlan(1);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        Client::factory()->for($account, 'account')->create(['cnpj' => $this->validCnpj('112223330001')]);

        $response = $this->actingAs($admin)->postJson('/api/clients', $this->payload([
            'cnpj' => $this->validCnpj('223334440001'),
        ]));

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->assertStringContainsString('upgrade', (string) $response->json('message'));
        $this->assertStringContainsString('upgrade', json_encode($response->json('errors'), JSON_THROW_ON_ERROR));
    }

    public function test_monitoring_toggle_persists_both_directions(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => false]);

        $this->actingAs($admin)->patchJson("/api/clients/{$client->id}/monitoring", [
            'monitoring_enabled' => true,
        ])->assertOk()->assertJsonPath('monitoring_enabled', true);

        $this->actingAs($admin)->getJson("/api/clients/{$client->id}")
            ->assertOk()->assertJsonPath('monitoring_enabled', true);

        $this->actingAs($admin)->patchJson("/api/clients/{$client->id}/monitoring", [
            'monitoring_enabled' => false,
        ])->assertOk()->assertJsonPath('monitoring_enabled', false);

        $this->actingAs($admin)->getJson("/api/clients/{$client->id}")
            ->assertOk()->assertJsonPath('monitoring_enabled', false);
    }

    public function test_monitoring_toggle_rejects_non_boolean(): void
    {
        $account = $this->accountWithPlan();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $client = Client::factory()->for($account, 'account')->create();

        $this->actingAs($admin)->patchJson("/api/clients/{$client->id}/monitoring", [
            'monitoring_enabled' => 'talvez',
        ])->assertStatus(422)->assertJsonValidationErrors(['monitoring_enabled']);
    }

    public function test_operator_has_full_crud_on_clients(): void
    {
        $account = $this->accountWithPlan();
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);

        $created = $this->actingAs($operator)->postJson('/api/clients', $this->payload())->assertCreated();
        $id = $created->json('id');

        $this->actingAs($operator)->getJson('/api/clients')->assertOk();
        $this->actingAs($operator)->getJson("/api/clients/{$id}")->assertOk();
        $this->actingAs($operator)->patchJson("/api/clients/{$id}", [
            'razao_social' => 'Editado Pelo Operador LTDA',
        ])->assertOk();
        $this->actingAs($operator)->patchJson("/api/clients/{$id}/monitoring", [
            'monitoring_enabled' => true,
        ])->assertOk();
        $this->actingAs($operator)->deleteJson("/api/clients/{$id}")->assertNoContent();
    }

    public function test_user_is_read_only_on_clients(): void
    {
        $account = $this->accountWithPlan();
        $user = $this->createUser($account, ['role' => UserRole::User]);
        $client = Client::factory()->for($account, 'account')->create();

        $this->actingAs($user)->getJson('/api/clients')->assertOk();
        $this->actingAs($user)->getJson("/api/clients/{$client->id}")->assertOk();

        $this->actingAs($user)->postJson('/api/clients', $this->payload([
            'cnpj' => $this->validCnpj('445556660001'),
        ]))->assertForbidden();
        $this->actingAs($user)->patchJson("/api/clients/{$client->id}", [
            'razao_social' => 'Tentativa',
        ])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/clients/{$client->id}/monitoring", [
            'monitoring_enabled' => true,
        ])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/clients/{$client->id}")->assertForbidden();
    }

    public function test_guest_cannot_reach_client_endpoints(): void
    {
        $this->getJson('/api/clients')->assertUnauthorized();
        $this->postJson('/api/clients', [])->assertUnauthorized();
        $this->getJson('/api/clients/1')->assertUnauthorized();
        $this->patchJson('/api/clients/1', [])->assertUnauthorized();
        $this->deleteJson('/api/clients/1')->assertUnauthorized();
    }
}
