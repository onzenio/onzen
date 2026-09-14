<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Enums\MonitoringRunStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class ClientCndTest extends TestCase
{
    use RefreshDatabase;

    /** @var FakeSerproTransport */
    private $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $this->transport);
    }

    public function test_cnd_requires_authentication(): void
    {
        $client = $this->entitledClient($this->createAccount());

        $this->getJson("/api/monitoring/clients/{$client->id}/cnd")->assertUnauthorized();
    }

    public function test_cnd_reads_the_current_situacao_fiscal_snapshot_without_an_external_call(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $enrollment = $this->situacaoFiscalEnrollment($account, $client);

        $run = MonitoringRun::factory()->completed()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
        ]);

        $snapshot = $this->situacaoFiscalSnapshot($account, $enrollment, [
            'run_id' => $run->id,
            'fingerprint' => hash('sha256', 'cnd-snapshot'),
            'verified_at' => now(),
            'data' => [
                'operation' => 'RELATORIOSITFIS92',
                'protocolo' => 'PROTO-1',
                'situacao' => 'regular',
                'nome_arquivo_relatorio' => 'RELATORIOSITFIS92.pdf',
            ],
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/clients/{$client->id}/cnd")
            ->assertOk();

        $response->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.client.razao_social', $client->razao_social)
            ->assertJsonPath('data.client.cnpj', $client->cnpj)
            ->assertJsonPath('data.state', 'available')
            ->assertJsonPath('data.cnd.operation_code', 'RELATORIOSITFIS92')
            ->assertJsonPath('data.cnd.family', 'sitfis')
            ->assertJsonPath('data.cnd.normalized', true)
            ->assertJsonPath('data.cnd.data.situacao', 'regular')
            ->assertJsonPath('data.cnd.data.protocolo', 'PROTO-1')
            ->assertJsonPath('data.cnd.verified_at', $snapshot->verified_at->toIso8601String())
            ->assertJsonPath('data.freshness', MonitoringSnapshot::FRESHNESS_FRESH)
            ->assertJsonPath('data.completeness', MonitoringSnapshot::COMPLETENESS_COMPLETE)
            ->assertJsonPath('data.verified_at', $snapshot->verified_at->toIso8601String());

        $this->assertSame([], $this->transport->tokenCalls);
        $this->assertSame([], $this->transport->callCalls);
    }

    public function test_cnd_without_a_snapshot_reports_factual_absence(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $this->situacaoFiscalEnrollment($account, $client);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/clients/{$client->id}/cnd")
            ->assertOk();

        $response->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.state', 'absent')
            ->assertJsonPath('data.cnd', null)
            ->assertJsonPath('data.freshness', MonitoringSnapshot::FRESHNESS_STALE)
            ->assertJsonPath('data.completeness', MonitoringSnapshot::COMPLETENESS_INCOMPLETE)
            ->assertJsonPath('data.verified_at', null);

        $this->assertSame([], $this->transport->tokenCalls);
        $this->assertSame([], $this->transport->callCalls);
    }

    public function test_cnd_completeness_fails_closed_when_the_latest_run_is_blocked(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $enrollment = $this->situacaoFiscalEnrollment($account, $client);

        $this->situacaoFiscalSnapshot($account, $enrollment, [
            'data' => ['situacao' => 'regular'],
        ]);
        MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
            'status' => MonitoringRunStatus::Blocked,
            'error_code' => 'transport_closed',
        ]);

        $this->actingAs($actor)
            ->getJson("/api/monitoring/clients/{$client->id}/cnd")
            ->assertOk()
            ->assertJsonPath('data.state', 'available')
            ->assertJsonPath('data.completeness', MonitoringSnapshot::COMPLETENESS_BLOCKED)
            ->assertJsonPath('data.cnd.data.situacao', 'regular');

        $this->assertSame([], $this->transport->callCalls);
    }

    public function test_cnd_completeness_is_incomplete_while_a_protocol_awaits(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $enrollment = $this->situacaoFiscalEnrollment($account, $client);

        $this->situacaoFiscalSnapshot($account, $enrollment);
        MonitoringRun::factory()->awaitingProtocol()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
        ]);

        $this->actingAs($actor)
            ->getJson("/api/monitoring/clients/{$client->id}/cnd")
            ->assertOk()
            ->assertJsonPath('data.completeness', MonitoringSnapshot::COMPLETENESS_INCOMPLETE);
    }

    public function test_cnd_reads_the_latest_situacao_fiscal_snapshot_and_sanitizes_internal_references(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $enrollment = $this->situacaoFiscalEnrollment($account, $client);

        $this->situacaoFiscalSnapshot($account, $enrollment, [
            'fingerprint' => hash('sha256', 'cnd-older'),
            'verified_at' => now()->subDay(),
            'data' => ['situacao' => 'irregular', 'pdf_storage_ref' => 'secret:old-report'],
        ]);

        $latest = $this->situacaoFiscalSnapshot($account, $enrollment, [
            'fingerprint' => hash('sha256', 'cnd-newer'),
            'verified_at' => now(),
            'data' => [
                'situacao' => 'regular',
                'pdf_storage_ref' => 'secret:new-report',
                'consumer_secret' => 'never-leaked',
            ],
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/api/monitoring/clients/{$client->id}/cnd")
            ->assertOk();

        $response->assertJsonPath('data.cnd.fingerprint', $latest->fingerprint)
            ->assertJsonPath('data.cnd.data.situacao', 'regular');

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('storage_ref', $content);
        $this->assertStringNotContainsString('never-leaked', $content);
        $this->assertStringNotContainsString('irregular', $content);
    }

    public function test_cnd_of_another_account_returns_404(): void
    {
        $actor = $this->actor($this->createAccount());
        $foreign = $this->entitledClient($this->createAccount());
        $enrollment = $this->situacaoFiscalEnrollment($foreign->account, $foreign);
        $this->situacaoFiscalSnapshot($foreign->account, $enrollment);

        $this->actingAs($actor)
            ->getJson("/api/monitoring/clients/{$foreign->id}/cnd")
            ->assertNotFound();
    }

    private function actor(Account $account, UserRole $role = UserRole::Admin): User
    {
        return $this->createUser($account, ['role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entitledClient(Account $account, array $attributes = []): Client
    {
        return Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => true,
            ...$attributes,
        ]);
    }

    private function situacaoFiscalEnrollment(Account $account, Client $client): MonitoringEnrollment
    {
        $definition = MonitoringDefinition::factory()->create([
            'id' => 'situacao-fiscal-'.uniqid(),
            'operations' => ['RELATORIOSITFIS92'],
        ]);

        return MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function situacaoFiscalSnapshot(Account $account, MonitoringEnrollment $enrollment, array $attributes = []): MonitoringSnapshot
    {
        return MonitoringSnapshot::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $enrollment->client_id,
            'run_id' => null,
            'operation_code' => 'RELATORIOSITFIS92',
            'family' => 'sitfis',
            'normalized' => true,
            'fingerprint' => hash('sha256', 'cnd-'.uniqid()),
            'data' => ['situacao' => 'regular'],
            'freshness' => MonitoringSnapshot::FRESHNESS_FRESH,
            'completeness' => MonitoringSnapshot::COMPLETENESS_COMPLETE,
            'verified_at' => now(),
            ...$attributes,
        ]);
    }
}
