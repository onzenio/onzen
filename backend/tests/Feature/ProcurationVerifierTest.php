<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorDocumentType;
use App\Enums\AuthorStatus;
use App\Exceptions\SerproBlockedException;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\PowerOfAttorney;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Services\Monitoring\ProcurationVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class ProcurationVerifierTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRATANTE_DOCUMENT = '65.396.736/0001-76';

    private const CLIENT_CNPJ = '11222333000181';

    private const AUTHOR_DOCUMENT = '12345678000195';

    private const FUTURE_EXPIRY = '20271127';

    private VaultResolver $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = app(VaultResolver::class);
    }

    public function test_confirmed_grant_enables_the_required_codes(): void
    {
        [$account, $certificate, $author, $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([
            $this->grantRow(self::FUTURE_EXPIRY, ['Acessar o sistema DCTFWeb']),
        ]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        $result = app(ProcurationVerifier::class)->verify($client, $definition);

        $this->assertTrue($result['verified']);
        $this->assertNull($result['reason']);
        $this->assertSame([], $result['missing_groups']);
        $this->assertSame(['00103' => '2027-11-27'], $result['granted']);
        $this->assertFalse($result['from_cache']);

        $power = PowerOfAttorney::query()->withoutGlobalScope('account')->sole();
        $this->assertSame($account->id, $power->account_id);
        $this->assertSame($client->id, $power->client_id);
        $this->assertSame('00103', $power->code);
        $this->assertSame(PowerOfAttorney::STATUS_ACTIVE, $power->status);
        $expected = Carbon::parse('2027-11-27', 'America/Sao_Paulo')->endOfDay();
        $this->assertSame($expected->getTimestamp(), $power->valid_until->getTimestamp());

        $this->assertCount(1, $transport->callCalls);
        $call = $transport->callCalls[0];
        $this->assertSame('/Consultar', $call['path']);
        $this->assertSame('PROCURACOES', $call['envelope']['pedidoDados']['idSistema']);
        $this->assertSame('OBTERPROCURACAO41', $call['envelope']['pedidoDados']['idServico']);
        $this->assertSame('1', $call['envelope']['pedidoDados']['versaoSistema']);
        $this->assertSame('65396736000176', $call['envelope']['contratante']['numero']);
        $this->assertSame(2, $call['envelope']['contratante']['tipo']);
        $this->assertSame(self::AUTHOR_DOCUMENT, $call['envelope']['autorPedidoDados']['numero']);
        $this->assertSame(2, $call['envelope']['autorPedidoDados']['tipo']);
        $this->assertSame(self::CLIENT_CNPJ, $call['envelope']['contribuinte']['numero']);
        $this->assertSame(2, $call['envelope']['contribuinte']['tipo']);

        $dados = json_decode((string) $call['envelope']['pedidoDados']['dados'], true);
        $this->assertSame(self::CLIENT_CNPJ, $dados['outorgante']);
        $this->assertSame(2, $dados['tipoOutorgante']);
        $this->assertSame(self::AUTHOR_DOCUMENT, $dados['outorgado']);
        $this->assertSame(2, $dados['tipoOutorgado']);

        $this->assertSame('oauth-access-token', $call['access_token']);
        $this->assertArrayNotHasKey('procurador_token', $call['options']);
        $this->assertStringStartsWith('obter-procuracao:', (string) $call['options']['idempotency_key']);
        $this->assertSame('homologacao', $call['options']['environment']);
    }

    public function test_absent_grant_yields_a_factual_negative_with_reason(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        $result = app(ProcurationVerifier::class)->verify($client, $definition);

        $this->assertFalse($result['verified']);
        $this->assertSame('outorga_pendente', $result['reason']);
        $this->assertSame([['00103']], $result['missing_groups']);
        $this->assertSame([], $result['granted']);
        $this->assertCount(1, $transport->callCalls);
        $this->assertSame(0, PowerOfAttorney::query()->withoutGlobalScope('account')->count());
    }

    public function test_expired_grant_yields_a_factual_negative_with_reason(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([
            $this->grantRow('20200101', ['Acessar o sistema DCTFWeb']),
        ]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        $verifier = app(ProcurationVerifier::class);
        $result = $verifier->verify($client, $definition);

        $this->assertFalse($result['verified']);
        $this->assertSame('outorga_expirada', $result['reason']);
        $this->assertFalse($verifier->holdsValidCode($client, '00103'));

        $power = PowerOfAttorney::query()->withoutGlobalScope('account')->sole();
        $this->assertSame('00103', $power->code);
        $this->assertTrue($power->valid_until->isPast());
    }

    public function test_cache_hit_avoids_a_second_call_and_misses_after_24h(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([
            $this->grantRow(self::FUTURE_EXPIRY, ['Acessar o sistema DCTFWeb']),
            $this->grantRow(self::FUTURE_EXPIRY, ['Acessar o sistema DCTFWeb']),
        ]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        $verifier = app(ProcurationVerifier::class);
        $first = $verifier->verify($client, $definition);

        $this->assertFalse($first['from_cache']);
        $this->assertCount(1, $transport->callCalls);
        $this->assertTrue($verifier->recentlyVerified($client));

        $second = $verifier->verify($client, $definition);

        $this->assertTrue($second['from_cache']);
        $this->assertTrue($second['verified']);
        $this->assertCount(1, $transport->callCalls);

        $this->travel(25)->hours();

        $third = $verifier->verify($client, $definition);

        $this->assertFalse($third['from_cache']);
        $this->assertCount(2, $transport->callCalls);
    }

    public function test_definition_requiring_procuracao_without_resolvable_codes_blocks_fail_closed(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'parc-paex',
            'requires_procuracao' => true,
            'procuration_codes' => null,
        ]);

        try {
            app(ProcurationVerifier::class)->verify($client, $definition);
            $this->fail('Expected the unresolved procuração requirement to block.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('procuracao_requisito_nao_resolvido', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
        $this->assertSame(0, PowerOfAttorney::query()->withoutGlobalScope('account')->count());
    }

    public function test_definition_without_procuracao_requirement_verifies_without_calls(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'situacao-mei',
            'requires_procuracao' => false,
            'procuration_codes' => null,
        ]);

        $result = app(ProcurationVerifier::class)->verify($client, $definition);

        $this->assertTrue($result['verified']);
        $this->assertNull($result['reason']);
        $this->assertSame([], $result['required_groups']);
        $this->assertFalse($result['from_cache']);
        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    public function test_code_outside_the_allowlist_is_refused_without_an_external_call(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'unknown-service',
            'requires_procuracao' => true,
            'procuration_codes' => ['99999'],
        ]);

        try {
            app(ProcurationVerifier::class)->verify($client, $definition);
            $this->fail('Expected a code outside the allowlist to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('procuracao_codigo_fora_da_allowlist', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
        $this->assertSame(0, PowerOfAttorney::query()->withoutGlobalScope('account')->count());
    }

    public function test_expired_certificate_blocks_the_verification_before_any_call(): void
    {
        [, $certificate, $author, $client] = $this->context();
        $certificate->update(['expires_at' => now()->subDay()]);
        $author->forceFill(['certificate_expires_at' => now()->addYear()])->save();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        try {
            app(ProcurationVerifier::class)->verify($client, $definition);
            $this->fail('Expected an expired certificate to block the verification.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('account_certificate_expired', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    public function test_missing_certificate_blocks_the_verification_before_any_call(): void
    {
        [$account, , , $client] = $this->context();
        AccountCertificate::query()->withoutGlobalScope('account')->where('account_id', $account->id)->delete();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        try {
            app(ProcurationVerifier::class)->verify($client, $definition);
            $this->fail('Expected a missing certificate to block the verification.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('account_certificate_unavailable', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    public function test_ineligible_author_blocks_the_verification_before_any_call(): void
    {
        [, $certificate, $author, $client] = $this->context();
        $author->forceFill([
            'status' => AuthorStatus::Active,
            'certificate_thumbprint' => $certificate->thumbprint,
            'certificate_expires_at' => now()->subDay(),
        ])->save();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        try {
            app(ProcurationVerifier::class)->verify($client, $definition);
            $this->fail('Expected an ineligible author to block the verification.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('author_ineligible', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    public function test_closed_gate_blocks_the_verification_before_any_call(): void
    {
        [, , , $client] = $this->context();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        try {
            app(ProcurationVerifier::class)->verify($client, $definition);
            $this->fail('Expected the closed gate to block the verification.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('serpro_gated', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    public function test_author_from_another_account_is_isolated(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([]);
        $this->app->instance(SerproTransport::class, $transport);

        $otherAccount = $this->createAccount();
        $otherCertificate = AccountCertificate::factory()->for($otherAccount, 'account')->create([
            'expires_at' => now()->addYear(),
        ]);
        $otherAuthor = SerproRequestAuthor::factory()->for($otherAccount, 'account')->create([
            'document' => '99888777000166',
            'document_type' => AuthorDocumentType::Pj,
        ]);
        $otherAuthor->useCertificate($otherCertificate);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'dctfweb',
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);

        try {
            app(ProcurationVerifier::class)->verify($client, $definition, null, $otherAuthor->refresh());
            $this->fail('Expected the cross-account author to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('author_account_mismatch', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    public function test_alternative_code_satisfies_a_paired_group(): void
    {
        [, , , $client] = $this->context();
        $this->openTransport();
        $transport = $this->fakeTransport([
            $this->grantRow(self::FUTURE_EXPIRY, ['Parcelamento de Débitos do Simples Nacional']),
        ]);
        $this->app->instance(SerproTransport::class, $transport);

        $definition = MonitoringDefinition::factory()->create([
            'id' => 'parcsn',
            'requires_procuracao' => true,
            'procuration_codes' => ['00076', '00188'],
        ]);

        $result = app(ProcurationVerifier::class)->verify($client, $definition);

        $this->assertTrue($result['verified']);
        $this->assertNull($result['reason']);
        $this->assertSame(['00076' => '2027-11-27'], $result['granted']);
    }

    public function test_required_groups_prefers_operation_codes_and_falls_back_to_the_definition(): void
    {
        $this->assertSame(
            [['00146']],
            ProcurationVerifier::requiredGroups(null, 'pgdas-declaracoes', 'GERARDAS12'),
        );
        $this->assertSame(
            [['00076', '00188']],
            ProcurationVerifier::requiredGroups(null, 'parcsn', 'PEDIDOSPARC163'),
        );
        $this->assertSame(
            [['00076', '00188']],
            ProcurationVerifier::requiredGroups(null, 'parcsn', 'GERARDAS161'),
        );
        $this->assertSame(
            [['00103']],
            ProcurationVerifier::requiredGroups(['00103'], 'dctfweb', 'CONSRECIBO32'),
        );
        $this->assertSame([], ProcurationVerifier::requiredGroups([], 'situacao-mei', 'DADOSCCMEI122'));
        $this->assertSame(
            ['00076', '00188'],
            ProcurationVerifier::requiredCodes(null, 'parcsn', 'PEDIDOSPARC163'),
        );
    }

    public function test_parse_granted_maps_service_names_and_keeps_the_latest_expiry(): void
    {
        $granted = ProcurationVerifier::parseGranted([
            ['dtexpiracao' => '20270110', 'sistemas' => ['Acessar o sistema DCTFWeb']],
            ['dtexpiracao' => '20271127', 'sistemas' => json_encode(['Acessar o sistema DCTFWeb', 'Serviço desconhecido'])],
            ['dtexpiracao' => 'not-a-date', 'sistemas' => ['Situação Fiscal do Contribuinte']],
        ]);

        $this->assertSame(['00103' => '2027-11-27'], $granted);
        $this->assertSame('2027-11-27', ProcurationVerifier::parseExpiry('2027-11-27'));
        $this->assertSame('2027-11-27', ProcurationVerifier::parseExpiry('20271127'));
        $this->assertNull(ProcurationVerifier::parseExpiry('20271340'));
        $this->assertNull(ProcurationVerifier::parseExpiry('not-a-date'));
    }

    /**
     * @return array{0: Account, 1: AccountCertificate, 2: SerproRequestAuthor, 3: Client, 4: SerproContract}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'expires_at' => now()->addYear(),
        ]);
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'document' => self::AUTHOR_DOCUMENT,
            'document_type' => AuthorDocumentType::Pj,
            'name' => 'Autor do Pedido de Dados',
        ]);
        $author->useCertificate($certificate);

        $client = Client::factory()->for($account, 'account')->create([
            'cnpj' => self::CLIENT_CNPJ,
            'monitoring_enabled' => true,
        ]);

        return [$account, $certificate, $author->refresh(), $client, $this->contractWithCredential()];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakeTransport(array $rows): FakeSerproTransport
    {
        return new FakeSerproTransport(
            [['access_token' => 'oauth-access-token', 'expires_in' => 300]],
            [['status' => 200, 'body' => ['dados' => $rows]]],
        );
    }

    /**
     * @param  list<string>  $systems
     * @return array<string, mixed>
     */
    private function grantRow(string $expiry, array $systems): array
    {
        return [
            'dtexpiracao' => $expiry,
            'nrsistemas' => count($systems),
            'sistemas' => $systems,
        ];
    }

    private function contractWithCredential(): SerproContract
    {
        $ref = 'secret:contratante-'.uniqid();
        $this->vault->put($ref, [
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => self::CONTRATANTE_DOCUMENT,
        ]);

        return SerproContract::factory()->create([
            'environment' => 'homologacao',
            'credential_ref' => $ref,
        ]);
    }

    private function openTransport(): void
    {
        config()->set('monitoring.dry_run', false);
        config()->set('monitoring.transport.approved', true);
    }
}
