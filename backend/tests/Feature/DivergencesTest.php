<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\PowerOfAttorney;
use App\Services\Monitoring\ProcurationVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class DivergencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_powers_of_attorney_shows_local_records_and_the_cached_verification(): void
    {
        [$account, $client] = $this->context();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $power = PowerOfAttorney::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'code' => '00103',
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_until' => now()->addYear(),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/monitoring/clients/{$client->id}/powers-of-attorney")
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.records.0.code', '00103')
            ->assertJsonPath('data.records.0.status', 'active')
            ->assertJsonPath('data.records.0.valid', true)
            ->assertJsonPath('data.records.0.divergent', false)
            ->assertJsonPath('data.requirements.0.satisfied', true)
            ->assertJsonPath('data.verification.cached', false);

        Cache::put(
            ProcurationVerifier::verifyCacheKey($client),
            ['00103' => '2027-11-27'],
            now()->addHours(ProcurationCatalog::VERIFY_CACHE_TTL_HOURS),
        );

        $this->actingAs($admin)
            ->getJson("/api/monitoring/clients/{$client->id}/powers-of-attorney")
            ->assertOk()
            ->assertJsonPath('data.verification.cached', true)
            ->assertJsonPath('data.verification.granted.00103', '2027-11-27');

        $this->assertNotNull($power->id);
    }

    public function test_powers_of_attorney_reads_and_verify_return_404_for_a_foreign_client(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $foreign = Client::factory()->for($this->createAccount(), 'account')->create();

        $this->actingAs($admin)
            ->getJson("/api/monitoring/clients/{$foreign->id}/powers-of-attorney")
            ->assertNotFound();

        $this->actingAs($admin)
            ->postJson("/api/monitoring/clients/{$foreign->id}/powers-of-attorney/verify")
            ->assertNotFound();
    }

    public function test_verify_run_is_refused_for_a_user_role(): void
    {
        [$account, $client] = $this->context();
        $user = $this->createUser($account, ['role' => UserRole::User]);

        $this->actingAs($user)
            ->postJson("/api/monitoring/clients/{$client->id}/powers-of-attorney/verify")
            ->assertForbidden();
    }

    public function test_operator_can_verify_and_a_missing_outorga_pauses_while_positive_resumes(): void
    {
        [$account, $client, $enrollment] = $this->context();
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);

        Cache::put(
            ProcurationVerifier::verifyCacheKey($client),
            [],
            now()->addHours(ProcurationCatalog::VERIFY_CACHE_TTL_HOURS),
        );

        $this->actingAs($operator)
            ->postJson("/api/monitoring/clients/{$client->id}/powers-of-attorney/verify")
            ->assertOk()
            ->assertJsonPath('data.results.0.definition_id', 'dctfweb')
            ->assertJsonPath('data.results.0.verified', false)
            ->assertJsonPath('data.results.0.reason', 'outorga_pendente')
            ->assertJsonPath('data.results.0.from_cache', true);

        $enrollment->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $enrollment->status);
        $this->assertSame('outorga pendente', $enrollment->pause_reason);

        PowerOfAttorney::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'code' => '00103',
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_until' => now()->addYear(),
        ]);

        Cache::put(
            ProcurationVerifier::verifyCacheKey($client),
            ['00103' => '2027-11-27'],
            now()->addHours(ProcurationCatalog::VERIFY_CACHE_TTL_HOURS),
        );

        $this->actingAs($operator)
            ->postJson("/api/monitoring/clients/{$client->id}/powers-of-attorney/verify")
            ->assertOk()
            ->assertJsonPath('data.results.0.verified', true)
            ->assertJsonPath('data.results.0.reason', null);

        $enrollment->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertNull($enrollment->pause_reason);
    }

    public function test_verify_without_any_definition_fails_closed(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson("/api/monitoring/clients/{$client->id}/powers-of-attorney/verify")
            ->assertStatus(409)
            ->assertJsonPath('error', 'procuracao_requisito_nao_resolvido');
    }

    public function test_divergences_list_missing_expired_and_divergent_clients_with_isolation(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'operations' => ['CONSDECLARACAO13'],
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        $pending = Client::factory()->for($account, 'account')->create([
            'razao_social' => 'Cliente Pendente',
            'monitoring_enabled' => true,
        ]);
        $this->enrollment($account, $pending, $definition);

        $expired = Client::factory()->for($account, 'account')->create([
            'razao_social' => 'Cliente Expirado',
            'monitoring_enabled' => true,
        ]);
        $this->enrollment($account, $expired, $definition);
        PowerOfAttorney::factory()->for($expired, 'client')->create([
            'account_id' => $account->id,
            'code' => '00103',
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_until' => now()->subDay(),
        ]);

        $divergent = Client::factory()->for($account, 'account')->create([
            'razao_social' => 'Cliente Divergente',
            'monitoring_enabled' => true,
        ]);
        PowerOfAttorney::factory()->for($divergent, 'client')->create([
            'account_id' => $account->id,
            'code' => '99999',
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_until' => now()->addYear(),
        ]);

        $foreignAccount = $this->createAccount();
        $foreignClient = Client::factory()->for($foreignAccount, 'account')->create([
            'razao_social' => 'Cliente de Outra Conta',
            'monitoring_enabled' => true,
        ]);
        $this->enrollment($foreignAccount, $foreignClient, $definition);

        $response = $this->actingAs($admin)
            ->getJson('/api/monitoring/powers-of-attorney/divergences')
            ->assertOk()
            ->assertJsonPath('data.table_version', ProcurationCatalog::TABLE_VERSION);

        $items = collect($response->json('data.items'))->keyBy('client.id');

        $this->assertTrue($items->has($pending->id));
        $this->assertEqualsCanonicalizing(
            ['outorga_pendente'],
            $items->get($pending->id)['reasons'],
        );
        $this->assertSame(['00103'], $items->get($pending->id)['codes']);

        $this->assertTrue($items->has($expired->id));
        $this->assertSame(['outorga_expirada'], $items->get($expired->id)['reasons']);

        $this->assertTrue($items->has($divergent->id));
        $this->assertSame(['outorga_divergente'], $items->get($divergent->id)['reasons']);
        $this->assertSame(['99999'], $items->get($divergent->id)['codes']);

        $this->assertFalse(
            $items->has($foreignClient->id),
            'Divergences must be isolated to the effective Account.',
        );
    }

    public function test_divergences_require_authentication(): void
    {
        $this->getJson('/api/monitoring/powers-of-attorney/divergences')->assertUnauthorized();
    }

    /**
     * @return array{0: Account, 1: Client, 2: MonitoringEnrollment}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create([
            'cnpj' => '11222333000181',
            'monitoring_enabled' => true,
        ]);
        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'operations' => ['CONSDECLARACAO13'],
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        return [$account, $client, $this->enrollment($account, $client, $definition)];
    }

    private function enrollment(
        Account $account,
        Client $client,
        MonitoringDefinition $definition,
    ): MonitoringEnrollment {
        return MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }
}
