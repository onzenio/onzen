<?php

namespace Tests\Feature;

use App\Contracts\ArtifactStore;
use App\Enums\UserRole;
use App\Exceptions\ArtifactStorageUnavailableException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MonitoringArtifact;
use App\Services\Artifacts\LocalArtifactStore;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Tests\TestCase;

class ArtifactTest extends TestCase
{
    use RefreshDatabase;

    private function store(): ArtifactStore
    {
        return app(ArtifactStore::class);
    }

    private function createArtifact(Account $account, string $contents, array $metadata = []): array
    {
        CurrentAccount::set($account->id);

        return $this->store()->put($contents, $metadata + [
            'kind' => 'pdf',
            'original_name' => 'documento.pdf',
        ]);
    }

    private function signedDownloadUrl(string $ref, ?Carbon $expiration = null): string
    {
        return URL::temporarySignedRoute(
            'monitoring.artifacts.download',
            $expiration ?? now()->addMinutes(5),
            ['ref' => $ref],
        );
    }

    public function test_contract_binding_resolves_the_local_store(): void
    {
        $this->assertInstanceOf(LocalArtifactStore::class, $this->store());
    }

    public function test_default_local_disk_is_the_private_app_directory(): void
    {
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
    }

    public function test_put_returns_an_opaque_ref_with_the_sha256_and_roundtrips_content(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);

        $contents = '%PDF-1.4 artifact-roundtrip-canary';
        $result = $this->store()->put($contents, [
            'kind' => 'pdf',
            'original_name' => 'recibo.pdf',
        ]);

        $this->assertArrayHasKey('ref', $result);
        $this->assertArrayHasKey('hash_sha256', $result);
        $this->assertSame(hash('sha256', $contents), $result['hash_sha256']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $result['ref']);
        $this->assertArrayNotHasKey('storage_path', $result);
        $this->assertStringNotContainsString('storage/app', json_encode($result));

        $this->assertSame($contents, $this->store()->get($result['ref']));
        $this->assertTrue($this->store()->exists($result['ref']));

        $artifact = MonitoringArtifact::query()->where('ref', $result['ref'])->sole();
        $this->assertStringStartsWith('monitoring/', $artifact->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($artifact->storage_path));
    }

    public function test_put_records_metadata_without_exposing_the_physical_path(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);
        $client = Client::factory()->for($account, 'account')->create();

        $result = $this->store()->put('conteúdo', [
            'client_id' => $client->id,
            'kind' => 'xml',
            'source' => 'CONSXMLDECLARACAO38',
            'original_name' => 'declaracao.xml',
        ]);

        $artifact = MonitoringArtifact::query()->where('ref', $result['ref'])->sole();
        $this->assertSame($account->id, $artifact->account_id);
        $this->assertSame($client->id, $artifact->client_id);
        $this->assertSame('xml', $artifact->kind);
        $this->assertSame('CONSXMLDECLARACAO38', $artifact->source);
        $this->assertSame('declaracao.xml', $artifact->original_name);
        $this->assertSame($result['hash_sha256'], $artifact->hash_sha256);

        $json = $artifact->toJson();
        $this->assertStringNotContainsString('storage_path', $json);
        $this->assertStringNotContainsString($artifact->storage_path, $json);
        $this->assertStringNotContainsString('storage/app/private', $json);
    }

    public function test_put_requires_an_account_context(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);

        $this->store()->put('sem-account');
    }

    public function test_get_returns_null_for_unknown_refs(): void
    {
        Storage::fake('local');

        $this->assertNull($this->store()->get('ref-que-nao-existe'));
        $this->assertFalse($this->store()->exists('ref-que-nao-existe'));
    }

    public function test_delete_removes_the_row_and_the_file(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $result = $this->createArtifact($account, 'conteudo-para-apagar');

        $artifact = MonitoringArtifact::query()->where('ref', $result['ref'])->sole();
        $path = $artifact->storage_path;

        $this->assertTrue($this->store()->delete($result['ref']));
        $this->assertNull($this->store()->get($result['ref']));
        $this->assertFalse($this->store()->exists($result['ref']));
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertDatabaseMissing('monitoring_artifacts', ['ref' => $result['ref']]);
        $this->assertFalse($this->store()->delete($result['ref']));
    }

    public function test_download_returns_404_when_the_physical_file_is_gone_but_the_row_remains(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::Admin]);
        $result = $this->createArtifact($account, 'lost-file-canary');

        $artifact = MonitoringArtifact::query()->where('ref', $result['ref'])->sole();
        Storage::disk('local')->delete($artifact->storage_path);

        $this->assertNull($this->store()->get($result['ref']));
        $this->assertFalse($this->store()->exists($result['ref']));

        $this->actingAs($user)->get($this->signedDownloadUrl($result['ref']))->assertNotFound();
        $this->assertDatabaseHas('monitoring_artifacts', ['ref' => $result['ref']]);
    }

    public function test_download_requires_authentication(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $result = $this->createArtifact($account, 'auth-canary');

        $this->get($this->signedDownloadUrl($result['ref']))->assertUnauthorized();
    }

    public function test_authorized_download_streams_the_file_and_records_the_access_audit(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::Admin]);
        $contents = '%PDF-1.4 download-canary-content';
        $result = $this->createArtifact($account, $contents);

        $response = $this->actingAs($user)->get($this->signedDownloadUrl($result['ref']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($contents, $response->streamedContent());

        $log = AuditLog::query()->where('action', 'monitoring.artifact.downloaded')->sole();
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame($account->id, $log->origin_account_id);
        $this->assertSame($result['ref'], $log->metadata['ref']);
        $this->assertSame($result['hash_sha256'], $log->metadata['hash_sha256']);
        $this->assertNotNull($log->created_at);
        $this->assertStringNotContainsString('download-canary-content', (string) json_encode($log->metadata));
    }

    public function test_admin_download_succeeds(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $result = $this->createArtifact($account, '%PDF-1.4 admin-canary');

        $response = $this->actingAs($admin)->get($this->signedDownloadUrl($result['ref']));

        $response->assertOk();
        $this->assertSame('%PDF-1.4 admin-canary', $response->streamedContent());
    }

    public function test_operator_download_succeeds(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $result = $this->createArtifact($account, '%PDF-1.4 operator-canary');

        $response = $this->actingAs($operator)->get($this->signedDownloadUrl($result['ref']));

        $response->assertOk();
        $this->assertSame('%PDF-1.4 operator-canary', $response->streamedContent());
    }

    public function test_user_of_the_same_account_gets_403_and_no_audit_row(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::User]);
        $result = $this->createArtifact($account, 'role-denied-canary');

        $this->actingAs($user)
            ->get($this->signedDownloadUrl($result['ref']))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'monitoring.artifact.downloaded']);
    }

    public function test_super_admin_does_not_bypass_the_artifact_role_matrix(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $superAdmin = $this->createUser($account, ['role' => UserRole::SuperAdmin]);
        $result = $this->createArtifact($account, 'super-admin-canary');

        $this->actingAs($superAdmin)
            ->get($this->signedDownloadUrl($result['ref']))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'monitoring.artifact.downloaded']);
    }

    public function test_download_of_another_accounts_artifact_returns_an_indistinguishable_404(): void
    {
        Storage::fake('local');
        $owner = $this->createAccount();
        $intruder = $this->createAccount();
        $user = $this->createUser($intruder);
        $result = $this->createArtifact($owner, 'cross-account-secret');

        $unknown = $this->actingAs($user)
            ->get($this->signedDownloadUrl('ref-inexistente-123'))
            ->assertNotFound()
            ->json();

        $crossAccount = $this->actingAs($user)
            ->get($this->signedDownloadUrl($result['ref']))
            ->assertNotFound()
            ->json();

        $this->assertSame($unknown, $crossAccount);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'monitoring.artifact.downloaded']);
    }

    public function test_expired_signed_link_returns_403_and_delivers_nothing(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account);
        $result = $this->createArtifact($account, 'expired-link-canary');

        $this->actingAs($user)
            ->get($this->signedDownloadUrl($result['ref'], now()->subMinute()))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'monitoring.artifact.downloaded']);
    }

    public function test_tampered_signature_returns_403(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account);
        $result = $this->createArtifact($account, 'tampered-canary');

        $url = $this->signedDownloadUrl($result['ref']).'&signature='.str_repeat('0', 64);

        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_storage_unavailable_returns_a_retryable_503_without_leaking_internals(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::Admin]);
        $result = $this->createArtifact($account, 'unavailable-canary');

        $this->app->instance(ArtifactStore::class, new class implements ArtifactStore
        {
            public function put(string $contents, array $metadata = []): array
            {
                throw new ArtifactStorageUnavailableException('physical /var/secret/artifacts path');
            }

            public function get(string $ref): ?string
            {
                throw new ArtifactStorageUnavailableException('physical /var/secret/artifacts path');
            }

            public function delete(string $ref): bool
            {
                throw new ArtifactStorageUnavailableException('physical /var/secret/artifacts path');
            }

            public function exists(string $ref): bool
            {
                throw new ArtifactStorageUnavailableException('physical /var/secret/artifacts path');
            }
        });

        $response = $this->actingAs($user)->get($this->signedDownloadUrl($result['ref']));

        $response->assertStatus(503)
            ->assertJsonPath('code', 'ARTIFACT_STORAGE_UNAVAILABLE')
            ->assertJsonPath('retryable', true);

        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertStringNotContainsString('/var/secret/artifacts', (string) $response->getContent());
        $this->assertStringNotContainsString('unavailable-canary', (string) $response->getContent());

        $log = AuditLog::query()->where('action', 'monitoring.artifact.download_failed')->sole();
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame($account->id, $log->origin_account_id);
        $this->assertSame($result['ref'], $log->metadata['ref']);
    }

    public function test_artifact_stays_listed_when_storage_is_unavailable(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::Admin]);
        $result = $this->createArtifact($account, 'listed-canary');

        $this->app->instance(ArtifactStore::class, new class implements ArtifactStore
        {
            public function put(string $contents, array $metadata = []): array
            {
                throw new ArtifactStorageUnavailableException;
            }

            public function get(string $ref): ?string
            {
                throw new ArtifactStorageUnavailableException;
            }

            public function delete(string $ref): bool
            {
                throw new ArtifactStorageUnavailableException;
            }

            public function exists(string $ref): bool
            {
                throw new ArtifactStorageUnavailableException;
            }
        });

        $this->actingAs($user)->get($this->signedDownloadUrl($result['ref']))->assertStatus(503);

        $this->assertDatabaseHas('monitoring_artifacts', [
            'ref' => $result['ref'],
            'account_id' => $account->id,
            'hash_sha256' => $result['hash_sha256'],
        ]);
    }

    public function test_link_endpoint_requires_authentication(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $result = $this->createArtifact($account, 'link-auth-canary');

        $this->getJson("/api/monitoring/artifacts/{$result['ref']}/url")->assertUnauthorized();
    }

    public function test_link_endpoint_returns_a_signed_download_url(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::Admin]);
        $result = $this->createArtifact($account, 'link-canary');

        $response = $this->actingAs($user)->getJson("/api/monitoring/artifacts/{$result['ref']}/url");

        $response->assertOk()
            ->assertJsonPath('expires_in_minutes', 15)
            ->assertJsonStructure(['url', 'expires_in_minutes']);

        $url = (string) $response->json('url');
        $this->assertStringContainsString($result['ref'], $url);
        $this->assertStringContainsString('signature=', $url);

        $this->actingAs($user)->get($url)->assertOk();
    }

    public function test_link_endpoint_returns_indistinguishable_404_for_unknown_and_cross_account_refs(): void
    {
        Storage::fake('local');
        $owner = $this->createAccount();
        $intruder = $this->createAccount();
        $user = $this->createUser($intruder, ['role' => UserRole::Admin]);
        $result = $this->createArtifact($owner, 'link-cross-account-secret');

        $unknown = $this->actingAs($user)
            ->getJson('/api/monitoring/artifacts/ref-inexistente-123/url')
            ->assertNotFound()
            ->json();

        $crossAccount = $this->actingAs($user)
            ->getJson("/api/monitoring/artifacts/{$result['ref']}/url")
            ->assertNotFound()
            ->json();

        $this->assertSame($unknown, $crossAccount);
    }

    public function test_link_endpoint_denies_roles_without_download_permission(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        $user = $this->createUser($account, ['role' => UserRole::User]);
        $result = $this->createArtifact($account, 'link-role-denied-canary');

        $this->actingAs($user)
            ->getJson("/api/monitoring/artifacts/{$result['ref']}/url")
            ->assertForbidden();
    }
}
