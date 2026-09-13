<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorDocumentType;
use App\Exceptions\SerproBlockedException;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AuditLog;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Services\Monitoring\ProcuradorTermService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class ProcuradorTermTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHOR_DOCUMENT = '12345678000195';

    private const CONTRATANTE_DOCUMENT = '65.396.736/0001-76';

    private const TOKEN = 'TOKEN-UUID-1';

    private const EXPIRES_AT = '2026-09-07T00:00:00-03:00';

    /** @var array{pfx: string, password: string}|null */
    private static ?array $a1 = null;

    private VaultResolver $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = app(VaultResolver::class);
    }

    public function test_build_term_xml_uses_the_canonical_legacy_structure(): void
    {
        $service = app(ProcuradorTermService::class);

        $xml = $service->buildTermXml(self::AUTHOR_DOCUMENT, 'PJ', self::CONTRATANTE_DOCUMENT, '20260906', '20260907');

        $this->assertStringContainsString('<termoDeAutorizacao>', $xml);
        $this->assertStringContainsString('id="API Integra Contador"', $xml);
        $this->assertStringContainsString('numero="'.self::AUTHOR_DOCUMENT.'"', $xml);
        $this->assertStringContainsString('numero="65396736000176"', $xml);
        $this->assertStringContainsString('tipo="PJ"', $xml);
        $this->assertStringContainsString('papel="autor pedido de dados"', $xml);
        $this->assertStringContainsString('papel="contratante"', $xml);
        $this->assertStringContainsString('dataAssinatura', $xml);
        $this->assertStringContainsString('data="20260906"', $xml);
        $this->assertStringContainsString('vigencia', $xml);
        $this->assertStringContainsString('data="20260907"', $xml);
        // Textos canônicos validados pelo AUTENTICAPROCURADOR (rejeita variação).
        $this->assertStringContainsString('como DESTINATÁRIO,', $xml);
        $this->assertStringContainsString('base legal para o tratamento dos dados', $xml);
        $this->assertStringContainsString('AUTOR PEDIDO DE DADOS (PROCURADOR ou OUTORGADO DO CONTRIBUINTE).', $xml);
    }

    public function test_signed_xml_verifies_and_tampering_is_detected(): void
    {
        $a1 = self::a1();
        $service = app(ProcuradorTermService::class);
        $unsigned = $service->buildTermXml(self::AUTHOR_DOCUMENT, 'PJ', self::CONTRATANTE_DOCUMENT, '20260906', '20260907');

        $signed = $service->signXmlWithPfx($unsigned, $a1['pfx'], $a1['password']);

        $this->assertStringContainsString('<Signature', $signed);
        $this->assertTrue($service->verifySignature($signed));
        $this->assertFalse($service->verifySignature(str_replace('20260907', '20260908', $signed)));
    }

    public function test_signing_fails_closed_with_the_wrong_certificate_password(): void
    {
        $a1 = self::a1();
        $service = app(ProcuradorTermService::class);
        $unsigned = $service->buildTermXml(self::AUTHOR_DOCUMENT, 'PJ', self::CONTRATANTE_DOCUMENT, '20260906', '20260907');

        $this->expectException(SerproBlockedException::class);
        $this->expectExceptionMessage('a1_certificate_unavailable');

        $service->signXmlWithPfx($unsigned, $a1['pfx'], 'wrong-password');
    }

    public function test_sign_term_xml_opens_the_account_certificate_from_the_vault(): void
    {
        $account = $this->createAccount();
        $certificate = $this->certificateFor($account);
        $author = $this->authorFor($account, $certificate);
        $service = app(ProcuradorTermService::class);
        $unsigned = $service->buildTermXml(self::AUTHOR_DOCUMENT, 'PJ', self::CONTRATANTE_DOCUMENT, '20260906', '20260907');

        $signed = $service->signTermXml($author, $unsigned);

        $this->assertTrue($service->verifySignature($signed));
    }

    public function test_signing_fails_closed_without_an_account_certificate(): void
    {
        $account = $this->createAccount();
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'document' => self::AUTHOR_DOCUMENT,
            'document_type' => AuthorDocumentType::Pj,
            'certificate_expires_at' => now()->addYear(),
        ]);

        $service = app(ProcuradorTermService::class);

        $this->expectException(SerproBlockedException::class);
        $this->expectExceptionMessage('account_certificate_unavailable');

        $service->signTermXml($author, $service->buildTermXml(
            self::AUTHOR_DOCUMENT,
            'PJ',
            self::CONTRATANTE_DOCUMENT,
            '20260906',
            '20260907',
        ));
    }

    public function test_submit_sends_the_envioxmlassinado_envelope_and_persists_the_token_in_the_vault(): void
    {
        $this->openTransport();
        [$author] = $this->submittedAuthor();

        $transport = $this->fakeTransport();
        $this->app->instance(SerproTransport::class, $transport);

        $service = app(ProcuradorTermService::class);
        $signed = $this->signedTerm($service, $author);

        $result = $service->submitTerm($author, $signed);

        $this->assertSame(self::TOKEN, $result['token']);

        $call = $transport->callCalls[0];
        $this->assertSame('/Apoiar', $call['path']);
        $this->assertSame('AUTENTICAPROCURADOR', $call['envelope']['pedidoDados']['idSistema']);
        $this->assertSame('ENVIOXMLASSINADO81', $call['envelope']['pedidoDados']['idServico']);
        $this->assertSame('1.0', $call['envelope']['pedidoDados']['versaoSistema']);
        $this->assertSame(
            base64_encode($signed),
            json_decode((string) $call['envelope']['pedidoDados']['dados'], true)['xml'],
        );
        $this->assertSame('65396736000176', $call['envelope']['contratante']['numero']);
        $this->assertSame(2, $call['envelope']['contratante']['tipo']);
        $this->assertSame(self::AUTHOR_DOCUMENT, $call['envelope']['autorPedidoDados']['numero']);
        $this->assertSame(2, $call['envelope']['autorPedidoDados']['tipo']);
        $this->assertSame(self::AUTHOR_DOCUMENT, $call['envelope']['contribuinte']['numero']);
        $this->assertSame('oauth-access-token', $call['access_token']);
        $this->assertArrayNotHasKey('procurador_token', $call['options']);

        $author->refresh();
        $ref = ProcuradorTermService::tokenRef($author->account_id);
        $this->assertSame(self::TOKEN, $this->vault->get($ref));
        $this->assertSame($ref, data_get($author->metadata, 'token_ref'));
        $this->assertSame('2026-09-07T03:00:00+00:00', data_get($author->metadata, 'token_expires_at'));
    }

    public function test_token_is_never_serialized_and_validity_is_recorded_in_the_audit(): void
    {
        $this->openTransport();
        [$author] = $this->submittedAuthor();
        $this->app->instance(SerproTransport::class, $this->fakeTransport());

        $service = app(ProcuradorTermService::class);
        $signed = $this->signedTerm($service, $author);

        $service->submitTerm($author, $signed);

        $serialized = json_encode($author->refresh()->toArray());
        $this->assertStringNotContainsString(self::TOKEN, $serialized);

        $log = AuditLog::query()->where('action', 'monitoring.procurador_term_submitted')->sole();
        $this->assertSame($author->account_id, $log->origin_account_id);
        $this->assertSame($author->id, $log->metadata['author_id']);
        $this->assertSame('2026-09-07T03:00:00+00:00', $log->metadata['expires_at']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($log->metadata));
    }

    public function test_submit_accepts_a_304_etag_as_a_revalidated_token(): void
    {
        $this->openTransport();
        [$author] = $this->submittedAuthor();

        $transport = new FakeSerproTransport(
            [['access_token' => 'oauth-access-token', 'expires_in' => 300]],
            [[
                'status' => 304,
                'body' => [],
                'headers' => ['etag' => ['"autenticar_procurador_token:TOKEN-UUID-304"']],
            ]],
        );
        $this->app->instance(SerproTransport::class, $transport);

        $service = app(ProcuradorTermService::class);
        $result = $service->submitTerm($author, $this->signedTerm($service, $author));

        $this->assertSame('TOKEN-UUID-304', $result['token']);
        $this->assertSame('TOKEN-UUID-304', $this->vault->get(ProcuradorTermService::tokenRef($author->account_id)));
    }

    public function test_submit_keeps_the_current_token_when_a_304_has_no_etag(): void
    {
        $this->openTransport();
        [$author] = $this->submittedAuthor();

        $transport = $this->fakeTransport();
        $this->app->instance(SerproTransport::class, $transport);
        $service = app(ProcuradorTermService::class);
        $service->submitTerm($author, $this->signedTerm($service, $author));

        $author->refresh();
        $this->assertSame(self::TOKEN, $this->vault->get(ProcuradorTermService::tokenRef($author->account_id)));

        $transport->callResponses[] = ['status' => 304, 'body' => [], 'headers' => []];
        $renewed = $service->submitTerm($author, $this->signedTerm($service, $author));

        $this->assertSame(self::TOKEN, $renewed['token']);
        $this->assertSame(self::TOKEN, $this->vault->get(ProcuradorTermService::tokenRef($author->account_id)));
    }

    public function test_submit_rejects_a_304_without_etag_or_stored_token(): void
    {
        $this->openTransport();
        [$author] = $this->submittedAuthor();

        $transport = new FakeSerproTransport(
            [['access_token' => 'oauth-access-token', 'expires_in' => 300]],
            [['status' => 304, 'body' => [], 'headers' => []]],
        );
        $this->app->instance(SerproTransport::class, $transport);
        $service = app(ProcuradorTermService::class);

        $this->expectException(SerproBlockedException::class);
        $this->expectExceptionMessage('procurador_token_missing');

        $service->submitTerm($author, $this->signedTerm($service, $author));
    }

    public function test_submit_fails_closed_when_the_transport_is_gated_and_makes_no_call(): void
    {
        [$author] = $this->submittedAuthor();

        $transport = $this->fakeTransport();
        $this->app->instance(SerproTransport::class, $transport);
        $service = app(ProcuradorTermService::class);

        try {
            $service->submitTerm($author, $this->signedTerm($service, $author));
            $this->fail('Expected gated transport to refuse the signed envelope.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('serpro_gated', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
        $this->assertNull($this->vault->get(ProcuradorTermService::tokenRef($author->account_id)));
    }

    public function test_submit_fails_closed_when_the_contratante_credential_is_missing(): void
    {
        $this->openTransport();
        $account = $this->createAccount();
        $author = $this->authorFor($account, $this->certificateFor($account));

        $transport = $this->fakeTransport();
        $this->app->instance(SerproTransport::class, $transport);
        $service = app(ProcuradorTermService::class);

        $this->expectException(SerproBlockedException::class);
        $this->expectExceptionMessage('serpro_credential_missing');

        $service->submitTerm($author, $this->signedTerm($service, $author));
    }

    public function test_renew_term_builds_signs_and_submits_the_author_term(): void
    {
        $this->openTransport();
        [$author] = $this->submittedAuthor();

        $transport = $this->fakeTransport([], [
            ['status' => 200, 'body' => [
                'dados' => [
                    'autenticar_procurador_token' => self::TOKEN,
                    'data_hora_expiracao' => self::EXPIRES_AT,
                ],
            ]],
        ]);
        $this->app->instance(SerproTransport::class, $transport);

        $result = app(ProcuradorTermService::class)->renewTerm($author);

        $this->assertSame(self::TOKEN, $result['token']);
        $envelope = $transport->callCalls[0]['envelope'];
        $signed = base64_decode((string) json_decode((string) $envelope['pedidoDados']['dados'], true)['xml']);
        $this->assertTrue(app(ProcuradorTermService::class)->verifySignature($signed));
        $this->assertSame(self::TOKEN, $this->vault->get(ProcuradorTermService::tokenRef($author->account_id)));
    }

    public function test_renew_term_refuses_an_ineligible_author_before_any_traffic(): void
    {
        $this->openTransport();
        [$author] = $this->submittedAuthor();
        $author->forceFill(['certificate_expires_at' => now()->subDay()])->save();

        $transport = $this->fakeTransport();
        $this->app->instance(SerproTransport::class, $transport);

        try {
            app(ProcuradorTermService::class)->renewTerm($author);
            $this->fail('Expected an ineligible author to be refused.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('author_ineligible', $exception->getMessage());
        }

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
    }

    public function test_fallback_expiry_is_the_next_midnight_in_brasilia(): void
    {
        $this->travelTo(Carbon::parse('2026-09-08 12:00:00', 'America/Sao_Paulo'));

        try {
            $service = app(ProcuradorTermService::class);
            $expected = Carbon::now('America/Sao_Paulo')->addDay()->startOfDay()->setTimezone(config('app.timezone', 'UTC'));

            $this->assertSame($expected->toIso8601String(), $service->fallbackExpiry()->toIso8601String());

            $parsed = $service->parseTokenResponse(['dados' => ['autenticar_procurador_token' => 'TOKEN-WITHOUT-EXPIRY']]);

            $this->assertSame('TOKEN-WITHOUT-EXPIRY', $parsed['token']);
            $this->assertSame($expected->toIso8601String(), $parsed['expires_at']->toIso8601String());
        } finally {
            $this->travelBack();
        }
    }

    public function test_parse_token_response_walks_nested_json_dados(): void
    {
        $service = app(ProcuradorTermService::class);

        $parsed = $service->parseTokenResponse([
            'status' => 200,
            'dados' => json_encode([
                'status' => 'Sucesso',
                'dados' => [
                    'autenticar_procurador_token' => 'NESTED-TOKEN',
                    'data_hora_expiracao' => '2026-09-09T00:00:00-03:00',
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertSame('NESTED-TOKEN', $parsed['token']);
        $this->assertTrue($parsed['expires_at']->equalTo(Carbon::parse('2026-09-09T00:00:00-03:00')));

        $this->assertNull($service->parseTokenResponse(['dados' => []]));
    }

    public function test_should_renew_when_the_token_is_missing_invalid_or_within_24h(): void
    {
        $service = app(ProcuradorTermService::class);
        $author = SerproRequestAuthor::factory()->create(['metadata' => []]);

        $this->assertTrue($service->shouldRenew($author));

        $author->update(['metadata' => ['token_expires_at' => now()->addHours(2)->toIso8601String()]]);
        $this->assertTrue($service->shouldRenew($author->refresh()));

        $author->update(['metadata' => ['token_expires_at' => now()->addDays(5)->toIso8601String()]]);
        $this->assertFalse($service->shouldRenew($author->refresh()));

        $author->update(['metadata' => ['token_expires_at' => 'not-a-date']]);
        $this->assertTrue($service->shouldRenew($author->refresh()));
    }

    public function test_secrets_never_reach_the_logs(): void
    {
        $handler = new TestHandler;
        Log::swap(new Logger('term-test', [$handler]));
        $a1 = self::a1();

        $this->openTransport();
        [$author] = $this->submittedAuthor();
        $this->app->instance(SerproTransport::class, $this->fakeTransport());

        $service = app(ProcuradorTermService::class);
        $service->submitTerm($author, $this->signedTerm($service, $author));

        $logged = [];
        foreach ($handler->getRecords() as $record) {
            $logged[] = $record->message.' '.json_encode($record->context);
        }

        $haystack = implode("\n", $logged);
        $this->assertStringNotContainsString(self::TOKEN, $haystack);
        $this->assertStringNotContainsString($a1['password'], $haystack);
        $this->assertStringNotContainsString(base64_encode($a1['pfx']), $haystack);
    }

    /**
     * @param  array<string, mixed>  $tokenResponse
     * @param  list<array{status: int, body: array<string, mixed>, headers?: array<string, mixed>}>  $callResponses
     */
    private function fakeTransport(array $tokenResponse = [], array $callResponses = []): FakeSerproTransport
    {
        $token = array_replace([
            'access_token' => 'oauth-access-token',
            'expires_in' => 300,
        ], $tokenResponse);

        $call = $callResponses === []
            ? [['status' => 200, 'body' => ['dados' => [
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => self::EXPIRES_AT,
            ]]]]
            : $callResponses;

        return new FakeSerproTransport([$token], $call);
    }

    /**
     * Create an Account with an author, certificate and Contratante credential.
     *
     * @return array{0: SerproRequestAuthor, 1: AccountCertificate, 2: SerproContract}
     */
    private function submittedAuthor(): array
    {
        $account = $this->createAccount();
        $certificate = $this->certificateFor($account);
        $author = $this->authorFor($account, $certificate);
        $contract = $this->contractWithCredential();

        return [$author, $certificate, $contract];
    }

    private function certificateFor(Account $account, ?string $password = null): AccountCertificate
    {
        $a1 = self::a1();
        $ref = 'secret:account-'.$account->id.'-certificate';
        $this->vault->put($ref, [
            'pfx_base64' => base64_encode($a1['pfx']),
            'certificate_password' => $password ?? $a1['password'],
        ]);

        return AccountCertificate::factory()->for($account, 'account')->create([
            'vault_ref' => $ref,
            'holder_name' => 'Escritório Teste',
            'expires_at' => now()->addYear(),
        ]);
    }

    private function authorFor(Account $account, AccountCertificate $certificate): SerproRequestAuthor
    {
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'document' => self::AUTHOR_DOCUMENT,
            'document_type' => AuthorDocumentType::Pj,
            'name' => 'Autor do Pedido de Dados',
        ]);
        $author->useCertificate($certificate);

        return $author->refresh();
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

    private function signedTerm(ProcuradorTermService $service, SerproRequestAuthor $author): string
    {
        return $service->signTermXml(
            $author,
            $service->buildTermXml(self::AUTHOR_DOCUMENT, 'PJ', self::CONTRATANTE_DOCUMENT, '20260906', '20260907'),
        );
    }

    private function openTransport(): void
    {
        config()->set('monitoring.dry_run', false);
        config()->set('monitoring.transport.approved', true);
    }

    /**
     * @return array{pfx: string, password: string}
     */
    private static function a1(): array
    {
        return self::$a1 ??= self::generateA1();
    }

    /**
     * @return array{pfx: string, password: string}
     */
    private static function generateA1(): array
    {
        $config = array_filter(['config' => self::opensslConfig()]);
        $key = openssl_pkey_new($config + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['CN' => 'Escritório Teste'], $key, $config);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 365, $config);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'a1-passphrase'));

        return ['pfx' => $pfx, 'password' => 'a1-passphrase'];
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
