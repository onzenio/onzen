<?php

namespace Tests\Feature;

use App\Enums\AuthorDocumentType;
use App\Enums\AuthorStatus;
use App\Models\AccountCertificate;
use App\Models\SerproRequestAuthor;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SerproRequestAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_serpro_request_authors_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('serpro_request_authors', [
            'id', 'account_id', 'document', 'document_type', 'name', 'status',
            'certificate_thumbprint', 'certificate_expires_at', 'metadata',
            'created_at', 'updated_at',
        ]));
    }

    public function test_author_persists_with_casts(): void
    {
        $account = $this->createAccount();

        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'document' => '12345678901',
            'document_type' => AuthorDocumentType::Pf,
            'name' => 'Maria Contadora',
            'metadata' => ['origin' => 'office'],
        ]);

        $author->refresh();

        $this->assertDatabaseHas('serpro_request_authors', [
            'id' => $author->id,
            'account_id' => $account->id,
            'document' => '12345678901',
            'document_type' => 1,
            'name' => 'Maria Contadora',
        ]);
        $this->assertSame(AuthorDocumentType::Pf, $author->document_type);
        $this->assertSame(AuthorStatus::Active, $author->status);
        $this->assertSame(['origin' => 'office'], $author->metadata);
    }

    public function test_author_links_to_active_certificate_and_inherits_thumbprint_and_expiry(): void
    {
        $account = $this->createAccount();
        $expiresAt = now()->addMonths(6)->startOfSecond();
        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'thumbprint' => 'cert-thumbprint',
            'expires_at' => $expiresAt,
        ]);

        $author = SerproRequestAuthor::factory()->for($account, 'account')->create();
        $author->useCertificate($certificate);

        $this->assertSame('cert-thumbprint', $author->certificate_thumbprint);
        $this->assertTrue($author->certificate_expires_at->equalTo($expiresAt));
        $this->assertSame(AuthorStatus::Active, $author->status);
        $this->assertTrue($author->isEligible());
    }

    public function test_author_is_ineligible_when_linked_certificate_is_already_expired(): void
    {
        $account = $this->createAccount();
        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'expires_at' => now()->subDay(),
        ]);

        $author = SerproRequestAuthor::factory()->for($account, 'account')->create();
        $author->useCertificate($certificate);

        $this->assertSame(AuthorStatus::Ineligible, $author->status);
        $this->assertFalse($author->isEligible());
    }

    public function test_author_becomes_ineligible_after_certificate_expires(): void
    {
        $account = $this->createAccount();
        $expiresAt = now()->addDay();
        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'expires_at' => $expiresAt,
        ]);
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create();
        $author->useCertificate($certificate);
        $this->assertTrue($author->isEligible());

        $this->travelTo($expiresAt->copy()->addMinute());
        $author->refreshEligibility();

        $this->assertSame(AuthorStatus::Ineligible, $author->status);
        $this->assertFalse($author->isEligible());
    }

    public function test_author_without_certificate_is_ineligible_fail_closed(): void
    {
        $author = SerproRequestAuthor::factory()->create([
            'certificate_thumbprint' => null,
            'certificate_expires_at' => null,
            'status' => AuthorStatus::Active,
        ]);

        $author->refreshEligibility();

        $this->assertSame(AuthorStatus::Ineligible, $author->status);
        $this->assertFalse($author->isEligible());
    }

    public function test_authors_are_scoped_to_current_account(): void
    {
        $mine = $this->createAccount();
        $other = $this->createAccount();
        SerproRequestAuthor::factory()->for($mine, 'account')->create();
        $otherAuthor = SerproRequestAuthor::factory()->for($other, 'account')->create();

        CurrentAccount::set($mine->id);

        $this->assertCount(1, SerproRequestAuthor::all());
        $this->assertNull(SerproRequestAuthor::find($otherAuthor->id));
    }

    public function test_author_creation_fills_account_from_current_context(): void
    {
        $account = $this->createAccount();
        CurrentAccount::set($account->id);

        $author = SerproRequestAuthor::factory()->make(['account_id' => null]);
        $author->save();

        $this->assertSame($account->id, $author->account_id);
    }
}
