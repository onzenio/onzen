<?php

namespace Tests\Feature;

use App\Contracts\VaultResolver;
use App\Enums\UserRole;
use App\Models\AccountCertificate;
use App\Models\AuditLog;
use App\Models\SerproRequestAuthor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class MonitoringCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_a_certificate_and_sees_masked_details(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->getJson('/api/monitoring/certificate')
            ->assertOk()
            ->assertJsonPath('data.state', 'absent');

        [$pfx, $password] = $this->makePfx();

        $response = $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.pfx', $pfx),
                'certificate_password' => $password,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.state', 'present')
            ->assertJsonPath('data.holder_name', 'ACME CONTABILIDADE LTDA')
            ->assertJsonPath('data.expired', false);

        $thumbprint = (string) $response->json('data.thumbprint');
        $this->assertMatchesRegularExpression('/^\*+[0-9a-f]{4}$/', $thumbprint);

        $this->assertEqualsWithDelta(
            now()->addDays(365)->getTimestamp(),
            Carbon::parse((string) $response->json('data.expires_at'))->getTimestamp(),
            120,
        );

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($password, $body);
        $this->assertStringNotContainsString(base64_encode($pfx), $body);

        $certificate = AccountCertificate::query()->sole();
        $this->assertSame($account->id, (int) $certificate->account_id);
        $this->assertSame($admin->id, (int) $certificate->uploaded_by_user_id);
        $this->assertStringEndsWith(substr((string) $certificate->thumbprint, -4), $thumbprint);

        $stored = app(VaultResolver::class)->get($certificate->vault_ref);
        $this->assertIsArray($stored);
        $decoded = base64_decode((string) ($stored['pfx_base64'] ?? ''), true);
        $this->assertIsString($decoded);
        $this->assertTrue(openssl_pkcs12_read($decoded, $certs, (string) ($stored['certificate_password'] ?? '')));
        $this->assertNotEmpty($certs['cert'] ?? null);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.certificate_stored',
            'origin_account_id' => $account->id,
        ]);
    }

    public function test_replacement_keeps_a_single_version_and_resyncs_authors(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        [$firstPfx, $firstPassword] = $this->makePfx('PRIMEIRA LTDA');

        $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('primeiro.pfx', $firstPfx),
                'certificate_password' => $firstPassword,
            ])
            ->assertCreated();

        $author = SerproRequestAuthor::factory()->create([
            'account_id' => $account->id,
        ]);
        $author->useCertificate(AccountCertificate::query()->sole());
        $firstThumbprint = $author->refresh()->certificate_thumbprint;

        [$secondPfx, $secondPassword] = $this->makePfx('SEGUNDA LTDA');

        $response = $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('segundo.pfx', $secondPfx),
                'certificate_password' => $secondPassword,
            ])
            ->assertOk();

        $response->assertJsonPath('data.state', 'present')
            ->assertJsonPath('data.holder_name', 'SEGUNDA LTDA');

        $this->assertSame(1, AccountCertificate::query()->count());
        $this->assertNotSame($firstThumbprint, $author->refresh()->certificate_thumbprint);
        $this->assertTrue($author->isEligible());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.certificate_replaced',
            'origin_account_id' => $account->id,
        ]);
    }

    public function test_invalid_password_or_unreadable_file_is_refused_without_touching_the_active_certificate(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        [$pfx, $password] = $this->makePfx();

        $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.pfx', $pfx),
                'certificate_password' => $password,
            ])
            ->assertCreated();

        $holder = AccountCertificate::query()->sole()->holder_name;

        $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.pfx', $pfx),
                'certificate_password' => 'senha-errada',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['certificate_password']);

        $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('lixo.pfx', 'not-a-pfx'),
                'certificate_password' => $password,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['certificate']);

        $this->assertSame($holder, AccountCertificate::query()->sole()->holder_name);

        $stored = app(VaultResolver::class)->get(AccountCertificate::query()->sole()->vault_ref);
        $this->assertIsArray($stored);
        $this->assertTrue(openssl_pkcs12_read(
            (string) base64_decode((string) ($stored['pfx_base64'] ?? ''), true),
            $certs,
            (string) ($stored['certificate_password'] ?? ''),
        ));
        $this->assertNotEmpty($certs['cert'] ?? null);
    }

    public function test_operator_and_user_cannot_manage_the_certificate(): void
    {
        $account = $this->createAccount();
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $user = $this->createUser($account, ['role' => UserRole::User]);
        AccountCertificate::factory()->for($account, 'account')->create();

        foreach ([$operator, $user] as $actor) {
            $this->actingAs($actor)->getJson('/api/monitoring/certificate')->assertForbidden();
            $this->actingAs($actor)->post('/api/monitoring/certificate', [])->assertForbidden();
            $this->actingAs($actor)->deleteJson('/api/monitoring/certificate')->assertForbidden();
        }

        $this->assertSame(1, AccountCertificate::query()->count());
    }

    public function test_super_admin_can_manage_the_certificate(): void
    {
        $account = $this->createAccount(['profile' => 'A']);
        $superAdmin = $this->createUser($account, ['role' => UserRole::SuperAdmin]);

        $this->actingAs($superAdmin)
            ->getJson('/api/monitoring/certificate')
            ->assertOk()
            ->assertJsonPath('data.state', 'absent');

        [$pfx, $password] = $this->makePfx();

        $this->actingAs($superAdmin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.pfx', $pfx),
                'certificate_password' => $password,
            ])
            ->assertCreated();

        $this->actingAs($superAdmin)
            ->deleteJson('/api/monitoring/certificate')
            ->assertOk()
            ->assertJsonPath('data.state', 'absent');
    }

    public function test_removal_forgets_the_vault_material_and_makes_authors_ineligible(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        [$pfx, $password] = $this->makePfx();

        $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.pfx', $pfx),
                'certificate_password' => $password,
            ])
            ->assertCreated();

        $certificate = AccountCertificate::query()->sole();
        $vaultRef = $certificate->vault_ref;

        $author = SerproRequestAuthor::factory()->create(['account_id' => $account->id]);
        $author->useCertificate($certificate);
        $this->assertTrue($author->refresh()->isEligible());

        $this->actingAs($admin)
            ->deleteJson('/api/monitoring/certificate')
            ->assertOk()
            ->assertJsonPath('data.state', 'absent');

        $this->assertSame(0, AccountCertificate::query()->count());
        $this->assertNull(app(VaultResolver::class)->get($vaultRef));
        $this->assertFalse($author->refresh()->isEligible());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.certificate_removed',
            'origin_account_id' => $account->id,
        ]);

        // Removing again is a factual no-op, not an error.
        $this->actingAs($admin)
            ->deleteJson('/api/monitoring/certificate')
            ->assertOk()
            ->assertJsonPath('data.state', 'absent');

        $this->assertSame(1, AuditLog::query()->where('action', 'account.certificate_removed')->count());
    }

    public function test_other_accounts_cannot_see_the_certificate(): void
    {
        $accountA = $this->createAccount();
        $accountB = $this->createAccount();
        $adminA = $this->createUser($accountA, ['role' => UserRole::Admin]);
        $adminB = $this->createUser($accountB, ['role' => UserRole::Admin]);

        [$pfx, $password] = $this->makePfx();

        $this->actingAs($adminA)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.pfx', $pfx),
                'certificate_password' => $password,
            ])
            ->assertCreated();

        $this->actingAs($adminB)
            ->getJson('/api/monitoring/certificate')
            ->assertOk()
            ->assertJsonPath('data.state', 'absent');

        $this->assertSame(1, AccountCertificate::query()->withoutGlobalScope('account')->count());
    }

    public function test_expired_certificate_is_visible_and_blocks_author_creation(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        [$pfx, $password] = $this->makePfx();

        $this->actingAs($admin)
            ->post('/api/monitoring/certificate', [
                'certificate' => UploadedFile::fake()->createWithContent('certificado.pfx', $pfx),
                'certificate_password' => $password,
            ])
            ->assertCreated();

        $this->travel(400)->days();

        $this->actingAs($admin)
            ->getJson('/api/monitoring/certificate')
            ->assertOk()
            ->assertJsonPath('data.state', 'present')
            ->assertJsonPath('data.expired', true);

        $this->actingAs($admin)
            ->postJson('/api/monitoring/authors', [
                'document' => '12345678901',
                'name' => 'Maria Contadora',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['certificate']);
    }

    /**
     * @return array{0: string, 1: string} PFX bytes and password.
     */
    private function makePfx(string $commonName = 'ACME CONTABILIDADE LTDA', string $password = 'senha-segura-123'): array
    {
        $config = array_filter(['config' => self::opensslConfig()]);
        $key = openssl_pkey_new($config + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(['CN' => $commonName], $key, $config + ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);

        $cert = openssl_csr_sign($csr, null, $key, 365, $config + ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);

        $this->assertTrue(openssl_pkcs12_export($cert, $pfx, $key, $password));

        return [$pfx, $password];
    }

    private static function opensslConfig(): ?string
    {
        foreach ([getenv('OPENSSL_CONF') ?: null, '/etc/ssl/openssl.cnf', '/usr/lib/ssl/openssl.cnf'] as $path) {
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
