<?php

namespace Tests\Feature;

use App\Enums\AuthorStatus;
use App\Models\AccountCertificate;
use App\Models\AuditLog;
use App\Models\SerproRequestAuthor;
use App\Support\CurrentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_certificates_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('account_certificates', [
            'id', 'account_id', 'vault_ref', 'holder_name', 'thumbprint',
            'expires_at', 'uploaded_by_user_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_certificate_persists_with_casts_and_relations(): void
    {
        $account = $this->createAccount();
        $uploader = $this->createUser($account);

        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'holder_name' => 'Escritório Exemplo',
            'thumbprint' => 'abc123',
            'expires_at' => '2027-01-31 12:00:00',
            'uploaded_by_user_id' => $uploader->id,
        ]);

        $this->assertDatabaseHas('account_certificates', [
            'id' => $certificate->id,
            'account_id' => $account->id,
            'holder_name' => 'Escritório Exemplo',
        ]);
        $this->assertInstanceOf(Carbon::class, $certificate->refresh()->expires_at);
        $this->assertTrue($certificate->account->is($account));
        $this->assertTrue($certificate->uploadedBy->is($uploader));
        $this->assertTrue($certificate->isActive());
        $this->assertFalse($certificate->isExpired());
    }

    public function test_certificate_is_expired_when_validity_has_passed(): void
    {
        $certificate = AccountCertificate::factory()->create([
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($certificate->isExpired());
        $this->assertFalse($certificate->isActive());
    }

    public function test_account_can_hold_only_one_certificate(): void
    {
        $account = $this->createAccount();
        AccountCertificate::factory()->for($account, 'account')->create();

        $this->expectException(QueryException::class);

        AccountCertificate::factory()->for($account, 'account')->create();
    }

    public function test_certificate_is_scoped_to_current_account(): void
    {
        $mine = $this->createAccount();
        $other = $this->createAccount();
        AccountCertificate::factory()->for($mine, 'account')->create();
        $otherCertificate = AccountCertificate::factory()->for($other, 'account')->create();

        CurrentAccount::set($mine->id);

        $this->assertCount(1, AccountCertificate::all());
        $this->assertNull(AccountCertificate::find($otherCertificate->id));
    }

    public function test_certificate_creation_fills_account_from_current_context(): void
    {
        $account = $this->createAccount();
        CurrentAccount::set($account->id);

        $certificate = AccountCertificate::factory()->make(['account_id' => null]);
        $certificate->save();

        $this->assertSame($account->id, $certificate->account_id);
    }

    public function test_replacement_keeps_a_single_version_and_marks_the_previous_unused(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'vault_ref' => 'secret:old-cert-ref',
            'thumbprint' => 'old-thumbprint',
            'expires_at' => now()->addMonth(),
        ]);

        $certificate->replace([
            'vault_ref' => 'secret:new-cert-ref',
            'thumbprint' => 'new-thumbprint',
            'holder_name' => 'Novo Titular',
            'expires_at' => now()->addYear(),
        ], $actor);

        $certificate->refresh();

        $this->assertSame(1, AccountCertificate::withoutGlobalScope('account')->where('account_id', $account->id)->count());
        $this->assertSame('secret:new-cert-ref', $certificate->vault_ref);
        $this->assertSame('new-thumbprint', $certificate->thumbprint);
        $this->assertDatabaseMissing('account_certificates', ['vault_ref' => 'secret:old-cert-ref']);
    }

    public function test_replacement_records_audit_without_secret_material(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'vault_ref' => 'secret:old-cert-ref',
            'thumbprint' => 'old-thumbprint',
        ]);

        $certificate->replace([
            'vault_ref' => 'secret:new-cert-ref',
            'thumbprint' => 'new-thumbprint',
        ], $actor);

        $log = AuditLog::query()->where('action', 'account.certificate_replaced')->sole();

        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($account->id, $log->origin_account_id);
        $this->assertSame('old-thumbprint', $log->metadata['previous_thumbprint']);
        $this->assertSame('new-thumbprint', $log->metadata['thumbprint']);

        $encoded = (string) json_encode($log->metadata);
        $this->assertStringNotContainsString('secret:old-cert-ref', $encoded);
        $this->assertStringNotContainsString('secret:new-cert-ref', $encoded);
    }

    public function test_replacement_audits_the_certificate_account_when_the_actor_belongs_to_another_account(): void
    {
        $actorAccount = $this->createAccount();
        $certificateAccount = $this->createAccount();
        $actor = $this->createUser($actorAccount);
        $certificate = AccountCertificate::factory()->for($certificateAccount, 'account')->create([
            'vault_ref' => 'secret:old-cert-ref',
            'thumbprint' => 'old-thumbprint',
        ]);

        $certificate->replace([
            'vault_ref' => 'secret:new-cert-ref',
            'thumbprint' => 'new-thumbprint',
        ], $actor);

        $log = AuditLog::query()->where('action', 'account.certificate_replaced')->sole();

        $this->assertSame($certificateAccount->id, $log->origin_account_id);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame('old-thumbprint', $log->metadata['previous_thumbprint']);
        $this->assertSame('new-thumbprint', $log->metadata['thumbprint']);

        $encoded = (string) json_encode($log->metadata);
        $this->assertStringNotContainsString('secret:old-cert-ref', $encoded);
        $this->assertStringNotContainsString('secret:new-cert-ref', $encoded);
    }

    public function test_replacement_syncs_linked_authors_to_the_new_certificate(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'thumbprint' => 'old-thumbprint',
            'expires_at' => now()->addMonth(),
        ]);
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create();
        $author->useCertificate($certificate);

        $newExpiresAt = now()->addYear()->startOfSecond();
        $certificate->replace([
            'vault_ref' => 'secret:new-cert-ref',
            'thumbprint' => 'new-thumbprint',
            'expires_at' => $newExpiresAt,
        ], $actor);

        $author->refresh();

        $this->assertSame('new-thumbprint', $author->certificate_thumbprint);
        $this->assertTrue($author->certificate_expires_at->equalTo($newExpiresAt));
        $this->assertSame(AuthorStatus::Active, $author->status);
    }

    public function test_vault_ref_is_masked_and_hidden_from_serialization(): void
    {
        $certificate = AccountCertificate::factory()->create([
            'vault_ref' => 'secret:pfx-bundle-abcdef-1234',
        ]);

        $masked = $certificate->maskedVaultRef();

        $this->assertIsString($masked);
        $this->assertStringStartsWith('secret:', $masked);
        $this->assertStringEndsWith('1234', $masked);
        $this->assertStringNotContainsString('pfx-bundle', $masked);

        $serialized = $certificate->toArray();
        $this->assertArrayNotHasKey('vault_ref', $serialized);
        $this->assertStringNotContainsString('pfx-bundle-abcdef-1234', (string) json_encode($serialized));
    }
}
