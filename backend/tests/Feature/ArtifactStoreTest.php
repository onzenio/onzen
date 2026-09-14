<?php

namespace Tests\Feature;

use App\Models\MonitoringArtifact;
use App\Services\ArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_gravacao_leitura_e_hash(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $store = app(ArtifactStore::class);

        $stored = $store->put($account->id, '%PDF-conteudo-sintetico', 'application/pdf');

        $this->assertSame(hash('sha256', '%PDF-conteudo-sintetico'), $stored['sha256']);
        $this->assertSame('%PDF-conteudo-sintetico', $store->get($account->id, $stored['ref']));
        $this->assertDatabaseHas('monitoring_artifacts', [
            'ref' => $stored['ref'],
            'sha256' => $stored['sha256'],
        ]);
    }

    public function test_api_nunca_expoe_caminho_fisico(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $store = app(ArtifactStore::class);

        $stored = $store->put($account->id, 'conteudo', 'application/pdf');
        $meta = $store->meta($account->id, $stored['ref']);

        $this->assertArrayNotHasKey('path', $meta);
        $json = json_encode([$stored, $meta]);
        $this->assertStringNotContainsString('storage', (string) $json);
        $this->assertStringNotContainsString('monitoring-artifacts/', (string) $json);

        $record = MonitoringArtifact::query()->withoutGlobalScopes()->firstOrFail()->toArray();
        $this->assertArrayNotHasKey('path', $record);
    }

    public function test_cross_account_nao_le(): void
    {
        Storage::fake('local');
        $a = $this->createAccount();
        $b = $this->createAccount();
        $store = app(ArtifactStore::class);

        $stored = $store->put($a->id, 'conteudo', 'application/pdf');

        $this->assertNull($store->get($b->id, $stored['ref']));
        $this->assertNull($store->meta($b->id, $stored['ref']));
        $this->assertNull($store->get($a->id, 'ref-inexistente'));
    }
}
