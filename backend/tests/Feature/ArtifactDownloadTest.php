<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MonitoringArtifact;
use App\Services\ArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ArtifactDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function stored(): array
    {
        Storage::fake('local');
        $account = $this->createAccount();

        return [$account, app(ArtifactStore::class)->put($account->id, '%PDF-ARTEFATO', 'application/pdf')];
    }

    public function test_download_sucesso_auditado(): void
    {
        [$account, $stored] = $this->stored();
        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        $url = $this->actingAs($user)
            ->getJson("/api/monitoring/artifacts/{$stored['ref']}/url")
            ->assertOk()
            ->json('url');

        $response = $this->actingAs($user)->get($url);
        $response->assertOk();
        $this->assertSame('%PDF-ARTEFATO', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitoring_artifact.downloaded']);
    }

    public function test_url_expirada_403(): void
    {
        [$account, $stored] = $this->stored();
        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        $expired = URL::temporarySignedRoute(
            'monitoring.artifacts.download',
            now()->subMinute(),
            ['ref' => $stored['ref']],
        );

        $this->actingAs($user)->get($expired)->assertForbidden();
    }

    public function test_404_cross_account(): void
    {
        [$account, $stored] = $this->stored();
        $stranger = $this->createUser($this->createAccount(), ['role' => UserRole::Operator]);

        $this->actingAs($stranger)
            ->getJson("/api/monitoring/artifacts/{$stored['ref']}/url")
            ->assertNotFound();
    }

    public function test_503_storage_indisponivel(): void
    {
        [$account, $stored] = $this->stored();
        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        // Linha existe, arquivo sumiu: storage indisponível.
        Storage::disk('local')->delete('monitoring-artifacts/'.$stored['ref']);
        $this->assertDatabaseHas('monitoring_artifacts', ['ref' => $stored['ref']]);

        $url = $this->actingAs($user)
            ->getJson("/api/monitoring/artifacts/{$stored['ref']}/url")
            ->assertOk()
            ->json('url');

        $this->actingAs($user)->get($url)->assertStatus(503);
    }
}
