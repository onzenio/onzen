<?php

namespace Tests\Feature;

use App\Contracts\ResultProjector;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorDocumentType;
use App\Enums\MonitoringRunStatus;
use App\Enums\UserRole;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\PowerOfAttorney;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproSettings;
use App\Services\Monitoring\ProcurationVerifier;
use App\Services\Monitoring\SerproExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeResultProjector;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class ProcurationPauseTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRATANTE_DOCUMENT = '65.396.736/0001-76';

    private const AUTHOR_DOCUMENT = '12345678000195';

    private FakeResultProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projector = new FakeResultProjector;
        $this->app->instance(ResultProjector::class, $this->projector);
    }

    public function test_executor_pauses_the_enrollment_on_a_known_negative_with_zero_traffic(): void
    {
        [$account, $client, $definition, $enrollment] = $this->context();
        $transport = $this->openTransport($account);

        $this->markGrants($client, []);

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'pause-known-negative'));

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('outorga_pendente', $run->error_code);
        $this->assertSame(0, $this->projector->calls());

        $enrollment->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $enrollment->status);
        $this->assertSame('outorga pendente', $enrollment->pause_reason);
        $this->assertSame(2, $enrollment->version);

        $this->assertCount(0, $transport->tokenCalls);
        $this->assertCount(0, $transport->callCalls);
    }

    public function test_executor_verifies_and_pauses_without_any_client_consult_traffic(): void
    {
        [$account, $client, $definition, $enrollment] = $this->context();
        $transport = $this->openTransport($account);
        $transport->callResponses = [['status' => 200, 'body' => ['dados' => []]]];

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'pause-live-negative'));

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('outorga_pendente', $run->error_code);
        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $enrollment->refresh()->status);
        $this->assertSame(0, $this->projector->calls());

        $this->assertCount(1, $transport->callCalls);
        $this->assertSame(
            'OBTERPROCURACAO41',
            $transport->callCalls[0]['envelope']['pedidoDados']['idServico'],
            'The gate may only verify the outorga; the consult itself is never sent.',
        );
    }

    public function test_an_unavailable_verification_blocks_without_pausing_the_enrollment(): void
    {
        [$account, $client, $definition, $enrollment] = $this->context();
        $this->openTransport($account, withAuthor: false);

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'verification-unavailable'));

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('author_pending', $run->error_code);

        $enrollment->refresh();
        $this->assertSame(
            MonitoringEnrollment::STATUS_ACTIVE,
            $enrollment->status,
            'An unavailable verification is not proof of absence: it must not pause.',
        );
        $this->assertNull($enrollment->pause_reason);
    }

    public function test_a_positive_verification_resumes_only_the_outorga_paused_associations_of_the_client(): void
    {
        [$account, $client, $definition, $paused] = $this->context();
        $paused->pause('outorga pendente');

        $manualDefinition = MonitoringDefinition::factory()->create([
            'id' => 'manual-definition',
            'operations' => ['CONSDECLARACAO13'],
            'requires_procuracao' => false,
            'procuration_codes' => null,
        ]);
        $manual = $this->enrollment($account, $client, $manualDefinition, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'pausa manual',
        ]);

        $foreignAccount = $this->createAccount();
        $foreignClient = Client::factory()->for($foreignAccount, 'account')->create(['monitoring_enabled' => true]);
        $foreign = $this->enrollment($foreignAccount, $foreignClient, $definition, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
        ]);

        $this->validGrant($client, '00103');
        $this->markGrants($client, ['00103' => '2027-11-27']);
        $this->markGrants($foreignClient, ['00103' => '2027-11-27']);

        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson("/api/monitoring/clients/{$client->id}/powers-of-attorney/verify")
            ->assertOk();

        $paused->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_ACTIVE, $paused->status);
        $this->assertNull($paused->pause_reason);

        $manual->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $manual->status);
        $this->assertSame('pausa manual', $manual->pause_reason);

        $this->assertSame(
            MonitoringEnrollment::STATUS_PAUSED,
            $foreign->refresh()->status,
            'Another Account\'s paused association must never be revived.',
        );
    }

    public function test_an_ended_association_is_never_resumed_by_a_positive_verification(): void
    {
        [$account, $client, $definition, $ended] = $this->context();
        $ended->pause('outorga pendente');
        $ended->end();
        $endedVersion = $ended->refresh()->version;

        $activeDefinition = MonitoringDefinition::factory()->create([
            'id' => 'active-after-ended',
            'operations' => ['CONSDECLARACAO13'],
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);
        $this->enrollment($account, $client, $activeDefinition);

        $this->validGrant($client, '00103');
        $this->markGrants($client, ['00103' => '2027-11-27']);

        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson("/api/monitoring/clients/{$client->id}/powers-of-attorney/verify")
            ->assertOk();

        $ended->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_ENDED, $ended->status);
        $this->assertSame($endedVersion, $ended->version);
    }

    public function test_verify_without_a_definition_fails_closed(): void
    {
        [$account, $client] = $this->context();
        $transport = $this->openTransport($account);

        try {
            app(ProcurationVerifier::class)->verify($client, null, 'DADOSCCMEI122');
            $this->fail('A verification without a definition must not succeed silently.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('procuracao_requisito_nao_resolvido', $exception->getMessage());
        }

        $this->assertCount(0, $transport->tokenCalls);
        $this->assertCount(0, $transport->callCalls);
    }

    public function test_executor_gate_passes_the_definition_so_unresolved_requirements_block(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => true]);
        $definition = MonitoringDefinition::factory()->create([
            'id' => 'situacao-mei',
            'operations' => ['DADOSCCMEI122'],
            'requires_procuracao' => true,
            'procuration_codes' => null,
        ]);
        $enrollment = $this->enrollment($account, $client, $definition);
        $transport = $this->openTransport($account);

        $run = $this->executor()->execute($this->executor()->claim($enrollment, 'unresolved-requirement'));

        $this->assertSame(MonitoringRunStatus::Blocked, $run->status);
        $this->assertSame('procuracao_requisito_nao_resolvido', $run->error_code);
        $this->assertCount(0, $transport->tokenCalls);
        $this->assertCount(0, $transport->callCalls);
    }

    private function executor(): SerproExecutor
    {
        return app(SerproExecutor::class);
    }

    /**
     * @return array{0: Account, 1: Client, 2: MonitoringDefinition, 3: MonitoringEnrollment}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create([
            'cnpj' => '11222333000181',
            'monitoring_enabled' => true,
        ]);
        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'operations' => ['CONSDECLARACAO13'],
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        return [$account, $client, $definition, $this->enrollment($account, $client, $definition)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrollment(
        Account $account,
        Client $client,
        MonitoringDefinition $definition,
        array $attributes = [],
    ): MonitoringEnrollment {
        return MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
            ...$attributes,
        ]);
    }

    /**
     * Open the effective transport gate and provide the Account certificate,
     * author and Contratante credential the live verification requires.
     */
    private function openTransport(Account $account, bool $withAuthor = true): FakeSerproTransport
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);

        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'expires_at' => now()->addYear(),
        ]);

        if ($withAuthor) {
            $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
                'document' => self::AUTHOR_DOCUMENT,
                'document_type' => AuthorDocumentType::Pj,
                'name' => 'Autor do Pedido de Dados',
            ]);
            $author->useCertificate($certificate);
        }

        $ref = 'secret:executor-'.uniqid();
        app(VaultResolver::class)->put($ref, (string) json_encode([
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => self::CONTRATANTE_DOCUMENT,
        ]));

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => $ref,
        ]);

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        return $transport;
    }

    private function validGrant(Client $client, string $code): PowerOfAttorney
    {
        return PowerOfAttorney::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'code' => $code,
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_until' => now()->addYear(),
        ]);
    }

    /**
     * @param  array<string, string>  $grants
     */
    private function markGrants(Client $client, array $grants): void
    {
        Cache::put(
            ProcurationVerifier::verifyCacheKey($client),
            $grants,
            now()->addHours(ProcurationCatalog::VERIFY_CACHE_TTL_HOURS),
        );
    }
}
