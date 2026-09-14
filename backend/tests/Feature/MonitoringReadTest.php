<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\Plan;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringReadTest extends TestCase
{
    use RefreshDatabase;

    private function accountClient(): array
    {
        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['monthly_query_volume' => 10])->id])->save();
        $client = Client::factory()->for($account->refresh(), 'account')->create();

        return [$account->refresh(), $client];
    }

    public function test_listagens_isoladas_e_404_cross_account(): void
    {
        [$account, $client] = $this->accountClient();
        $service = app(SnapshotService::class);
        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'regular']);
        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'irregular']);

        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        $this->actingAs($user)->getJson('/api/monitoring/dashboard')->assertOk()
            ->assertJsonPath('open_alerts', 1);

        $this->actingAs($user)->getJson("/api/monitoring/clients/{$client->id}/snapshots")->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($user)->getJson("/api/monitoring/clients/{$client->id}/changes")->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($user)->getJson("/api/monitoring/clients/{$client->id}/alerts")->assertOk()
            ->assertJsonPath('meta.total', 1);

        $stranger = $this->createUser($this->createAccount(), ['role' => UserRole::Operator]);

        foreach (['snapshots', 'changes', 'alerts', 'cnd'] as $resource) {
            $this->actingAs($stranger)->getJson("/api/monitoring/clients/{$client->id}/{$resource}")->assertNotFound();
        }
    }

    public function test_cnd_lida_do_snapshot_sem_consulta_ao_abrir(): void
    {
        [$account, $client] = $this->accountClient();
        app(SnapshotService::class)->record($account, $client, 'sitfis', [
            'situacao_fiscal' => 'regular', 'cnd_disponivel' => true,
        ]);

        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        Http::preventStrayRequests();

        $this->actingAs($user)->getJson("/api/monitoring/clients/{$client->id}/cnd")->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('situacao_fiscal', 'regular');

        Http::assertSentCount(0);
    }

    public function test_cnd_sem_snapshot_estado_explicito(): void
    {
        [$account, $client] = $this->accountClient();
        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        $this->actingAs($user)->getJson("/api/monitoring/clients/{$client->id}/cnd")->assertOk()
            ->assertJsonPath('available', false);
    }

    public function test_acknowledge_via_api(): void
    {
        [$account, $client] = $this->accountClient();
        $service = app(SnapshotService::class);
        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'regular']);
        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'irregular']);
        $alert = MonitoringAlert::query()->withoutGlobalScopes()->firstOrFail();

        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        $this->actingAs($user)
            ->postJson("/api/monitoring/clients/{$client->id}/alerts/{$alert->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('status', 'acknowledged');
    }
}
