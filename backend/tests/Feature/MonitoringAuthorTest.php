<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorDocumentType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class MonitoringAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_and_creates_an_author_linked_to_the_active_certificate(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'thumbprint' => 'aaaabbbbccccdddd',
            'expires_at' => now()->addMonths(6),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/monitoring/authors')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $response = $this->actingAs($admin)
            ->postJson('/api/monitoring/authors', [
                'document' => '123.456.789-01',
                'name' => 'Maria Contadora',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Maria Contadora')
            ->assertJsonPath('data.document', '*******8901')
            ->assertJsonPath('data.document_type', AuthorDocumentType::Pf->value)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.eligible', true);

        $this->assertSame(
            $certificate->refresh()->expires_at->getTimestamp(),
            Carbon::parse((string) $response->json('data.certificate_expires_at'))->getTimestamp(),
        );

        $this->assertStringNotContainsString('12345678901', (string) $response->getContent());
        $this->assertStringNotContainsString('aaaabbbbccccdddd', (string) $response->getContent());

        $this->assertDatabaseHas('serpro_request_authors', [
            'account_id' => $account->id,
            'document' => '12345678901',
            'document_type' => AuthorDocumentType::Pf->value,
            'name' => 'Maria Contadora',
            'status' => 'active',
            'certificate_thumbprint' => 'aaaabbbbccccdddd',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/monitoring/authors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Maria Contadora');
    }

    public function test_author_creation_requires_an_active_account_certificate(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson('/api/monitoring/authors', [
                'document' => '12345678901',
                'name' => 'Maria Contadora',
            ])
            ->assertUnprocessable();

        AccountCertificate::factory()->for($account, 'account')->expired()->create();

        $this->actingAs($admin)
            ->postJson('/api/monitoring/authors', [
                'document' => '12345678901',
                'name' => 'Maria Contadora',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('serpro_request_authors', 0);
    }

    public function test_author_creation_validates_the_document(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        AccountCertificate::factory()->for($account, 'account')->create();

        $this->actingAs($admin)
            ->postJson('/api/monitoring/authors', [
                'document' => '123',
                'name' => 'Maria Contadora',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('serpro_request_authors', 0);
    }

    public function test_operator_and_user_cannot_manage_authors(): void
    {
        $account = $this->createAccount();
        AccountCertificate::factory()->for($account, 'account')->create();

        foreach ([UserRole::Operator, UserRole::User] as $role) {
            /** @var User $actor */
            $actor = $this->createUser($account, ['role' => $role]);

            $this->actingAs($actor)->getJson('/api/monitoring/authors')->assertForbidden();
            $this->actingAs($actor)->postJson('/api/monitoring/authors', [
                'document' => '12345678901',
                'name' => 'Maria Contadora',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('serpro_request_authors', 0);
    }

    public function test_authors_are_isolated_to_the_effective_account(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $mine = SerproRequestAuthor::factory()->for($account, 'account')->create(['name' => 'Meu Autor']);
        $foreign = SerproRequestAuthor::factory()->for($this->createAccount(), 'account')->create(['name' => 'Autor Alheio']);

        $response = $this->actingAs($admin)->getJson('/api/monitoring/authors')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Meu Autor');

        $this->assertStringNotContainsString('Autor Alheio', (string) $response->getContent());
        $this->assertNotNull($mine->id);
        $this->assertNotNull($foreign->id);
    }

    public function test_author_creation_never_submits_the_term_automatically(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $certificate = AccountCertificate::factory()->for($account, 'account')->create();
        $transport = $this->openTransport($account);

        $this->actingAs($admin)
            ->postJson('/api/monitoring/authors', [
                'document' => '12345678901',
                'name' => 'Maria Contadora',
            ])
            ->assertCreated();

        $this->assertCount(0, $transport->tokenCalls);
        $this->assertCount(0, $transport->callCalls);
        $this->assertNotNull($certificate->id);
    }

    public function test_term_submission_of_another_account_returns_404(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $foreign = SerproRequestAuthor::factory()->for($this->createAccount(), 'account')->create();

        $this->actingAs($admin)
            ->postJson("/api/monitoring/authors/{$foreign->id}/term")
            ->assertNotFound();
    }

    public function test_on_demand_term_submission_is_refused_while_the_transport_is_gated(): void
    {
        $account = $this->createAccount();
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $certificate = AccountCertificate::factory()->for($account, 'account')->create();
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create();
        $author->useCertificate($certificate);

        $this->actingAs($admin)
            ->postJson("/api/monitoring/authors/{$author->id}/term")
            ->assertStatus(409)
            ->assertJsonPath('error', 'serpro_gated');
    }

    private function openTransport(Account $account): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $ref = 'secret:authors-'.uniqid();
        app(VaultResolver::class)->put($ref, (string) json_encode([
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => '65.396.736/0001-76',
        ]));

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => $ref,
        ]);

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        return $transport;
    }
}
