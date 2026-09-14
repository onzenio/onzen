<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ParcelmentOrder;
use App\Services\ArtifactStore;
use App\Services\ParcelmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParcelmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::factory()->create();
    }

    public function test_detalhe_normalizado(): void
    {
        $client = $this->client();
        $user = $this->createUser($client->account, ['role' => UserRole::Operator]);

        $orders = app(ParcelmentService::class)->list($client, '012');
        $order = $orders->firstOrFail();

        $this->actingAs($user)
            ->getJson("/api/monitoring/clients/{$client->id}/parcelamentos/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order_number', 'PED-SINTETICO-1')
            ->assertJsonPath('has_guia', false)
            ->assertJsonCount(2, 'installments')
            ->assertJsonCount(1, 'payments');
    }

    public function test_download_guia_ja_gerada(): void
    {
        $client = $this->client();
        $user = $this->createUser($client->account, ['role' => UserRole::Operator]);

        $order = app(ParcelmentService::class)->list($client, '012')->firstOrFail();
        $stored = app(ArtifactStore::class)->put($client->account_id, '%PDF-GUIA-SINTETICA', 'application/pdf');
        $order->forceFill(['guia_ref' => $stored['ref']])->save();

        $response = $this->actingAs($user)
            ->getJson("/api/monitoring/clients/{$client->id}/parcelamentos/orders/{$order->id}/guia");

        $response->assertOk();
        $this->assertSame('%PDF-GUIA-SINTETICA', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'parcelment_guia.downloaded']);
    }

    public function test_guia_ausente_nao_emite(): void
    {
        $client = $this->client();
        $user = $this->createUser($client->account, ['role' => UserRole::Operator]);

        $order = app(ParcelmentService::class)->list($client, '012')->firstOrFail();

        $this->actingAs($user)
            ->getJson("/api/monitoring/clients/{$client->id}/parcelamentos/orders/{$order->id}/guia")
            ->assertNotFound()
            ->assertJsonPath('message', 'Guia ainda não gerada para este pedido. Nenhuma emissão foi realizada.');

        $this->assertDatabaseCount('monitoring_artifacts', 0);
    }

    public function test_cross_account_404(): void
    {
        $client = $this->client();
        $order = app(ParcelmentService::class)->list($client, '012')->firstOrFail();

        $stranger = $this->createUser($this->createAccount(), ['role' => UserRole::Operator]);

        $this->actingAs($stranger)
            ->getJson("/api/monitoring/clients/{$client->id}/parcelamentos/orders/{$order->id}")
            ->assertNotFound();
    }

    public function test_modalidade_indisponivel_422(): void
    {
        $client = $this->client();
        $user = $this->createUser($client->account, ['role' => UserRole::Operator]);

        $this->actingAs($user)
            ->getJson("/api/monitoring/clients/{$client->id}/parcelamentos/FGTS")
            ->assertStatus(422);
    }
}
