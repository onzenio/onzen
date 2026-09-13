<?php

namespace Tests\Feature;

use App\Enums\AuthorStatus;
use App\Exceptions\SerproBlockedException;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproRequestAuthor;
use App\Services\Monitoring\ProcurationA1Authenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProcurationA1AuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_an_active_certificate_with_an_eligible_author(): void
    {
        $account = $this->createAccount();
        $certificate = $this->certificateFor($account);
        $client = Client::factory()->for($account, 'account')->create();
        $author = $this->authorFor($account, $certificate);

        $authenticated = app(ProcurationA1Authenticator::class)->authenticate($account, $client, $author);

        $this->assertSame($certificate->id, $authenticated->id);
        $this->assertSame($certificate->thumbprint, $authenticated->thumbprint);
    }

    public function test_refuses_a_missing_certificate_with_a_factual_reason(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create();
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'status' => AuthorStatus::Active,
            'certificate_expires_at' => now()->addYear(),
        ]);

        try {
            app(ProcurationA1Authenticator::class)->authenticate($account, $client, $author);
            $this->fail('Expected the missing certificate to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('account_certificate_unavailable', $exception->getMessage());
        }
    }

    public function test_refuses_an_expired_certificate_with_a_factual_reason(): void
    {
        $account = $this->createAccount();
        $certificate = AccountCertificate::factory()->for($account, 'account')->expired()->create();
        $client = Client::factory()->for($account, 'account')->create();
        $author = $this->authorFor($account, $certificate);

        try {
            app(ProcurationA1Authenticator::class)->authenticate($account, $client, $author);
            $this->fail('Expected the expired certificate to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('account_certificate_expired', $exception->getMessage());
        }
    }

    public function test_refuses_an_ineligible_author(): void
    {
        $account = $this->createAccount();
        $certificate = $this->certificateFor($account);
        $client = Client::factory()->for($account, 'account')->create();
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'status' => AuthorStatus::Active,
            'certificate_thumbprint' => $certificate->thumbprint,
            'certificate_expires_at' => now()->subDay(),
        ]);

        try {
            app(ProcurationA1Authenticator::class)->authenticate($account, $client, $author);
            $this->fail('Expected the ineligible author to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('author_ineligible', $exception->getMessage());
        }
    }

    public function test_refuses_an_author_from_another_account(): void
    {
        $account = $this->createAccount();
        $this->certificateFor($account);
        $client = Client::factory()->for($account, 'account')->create();

        $otherAccount = $this->createAccount();
        $author = $this->authorFor($otherAccount, $this->certificateFor($otherAccount));

        try {
            app(ProcurationA1Authenticator::class)->authenticate($account, $client, $author);
            $this->fail('Expected the cross-account author to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('author_account_mismatch', $exception->getMessage());
        }
    }

    public function test_refuses_a_client_from_another_account(): void
    {
        $account = $this->createAccount();
        $certificate = $this->certificateFor($account);
        $author = $this->authorFor($account, $certificate);

        $otherAccount = $this->createAccount();
        $client = Client::factory()->for($otherAccount, 'account')->create();

        try {
            app(ProcurationA1Authenticator::class)->authenticate($account, $client, $author);
            $this->fail('Expected the cross-account client to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('client_account_mismatch', $exception->getMessage());
        }
    }

    public function test_refuses_an_author_linked_to_a_different_certificate(): void
    {
        $account = $this->createAccount();
        $certificate = $this->certificateFor($account);
        $client = Client::factory()->for($account, 'account')->create();
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'status' => AuthorStatus::Active,
            'certificate_thumbprint' => 'sha256:another-certificate',
            'certificate_expires_at' => now()->addYear(),
        ]);

        $this->assertNotSame($certificate->thumbprint, $author->certificate_thumbprint);

        try {
            app(ProcurationA1Authenticator::class)->authenticate($account, $client, $author);
            $this->fail('Expected the mismatched author certificate to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('author_certificate_mismatch', $exception->getMessage());
        }
    }

    private function certificateFor(Account $account): AccountCertificate
    {
        return AccountCertificate::factory()->for($account, 'account')->create([
            'expires_at' => now()->addYear(),
        ]);
    }

    private function authorFor(Account $account, AccountCertificate $certificate): SerproRequestAuthor
    {
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create();

        return $author->useCertificate($certificate);
    }
}
