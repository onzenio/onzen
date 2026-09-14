<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\SerproCredentials;
use App\Integrations\Serpro\Transport\HttpOAuthMtlsTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpOAuthMtlsTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('monitoring.token_url', 'https://authentication.example.test/authenticate');
        config()->set('monitoring.base_url', 'https://gateway.example.test/integra-contador/v1');
        config()->set('monitoring.transport.timeout', 15);
        config()->set('monitoring.transport.connect_timeout', 5);
        config()->set('monitoring.transport.role_type', 'TERCEIROS');
    }

    public function test_token_sends_client_credentials_form_with_basic_auth_and_role_type(): void
    {
        $captured = null;
        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = ['request' => $request, 'options' => $options];

            return Http::response([
                'access_token' => 'opaque-access-token',
                'expires_in' => 300,
                'jwt_token' => 'opaque-jwt',
            ], 200);
        });

        $payload = (new HttpOAuthMtlsTransport)->token($this->credentials(), 'homologacao');

        $this->assertSame('opaque-access-token', $payload['access_token']);
        $this->assertSame(300, $payload['expires_in']);
        $this->assertSame('opaque-jwt', $payload['jwt_token']);

        $sent = $captured['request'];
        $this->assertSame('POST', $sent->method());
        $this->assertSame('https://authentication.example.test/authenticate', $sent->url());
        $this->assertTrue($sent->isForm());
        $this->assertSame('client_credentials', $sent->data()['grant_type']);
        $this->assertSame('179024', $sent->data()['client_id']);
        $this->assertSame('consumer-secret', $sent->data()['client_secret']);
        $this->assertTrue($sent->hasHeader('Authorization', 'Basic '.base64_encode('179024:consumer-secret')));
        $this->assertTrue($sent->hasHeader('Role-Type', 'TERCEIROS'));
    }

    public function test_token_honors_configured_timeouts_and_role_type(): void
    {
        config()->set('monitoring.transport.timeout', 30);
        config()->set('monitoring.transport.connect_timeout', 10);
        config()->set('monitoring.transport.role_type', 'PROPRIO');

        $options = null;
        Http::fake(function (Request $request, array $filtered) use (&$options) {
            $options = $filtered;

            return Http::response(['access_token' => 'opaque'], 200);
        });

        (new HttpOAuthMtlsTransport)->token($this->credentials(), 'producao');

        $this->assertSame(30, $options['timeout']);
        $this->assertSame(10, $options['connect_timeout']);
        $this->assertTrue($options['verify']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Role-Type', 'PROPRIO'));
    }

    public function test_token_requires_certificate_and_password_before_any_request(): void
    {
        Http::fake();

        $base = ['e_cnpj' => '179024', 'consumer_secret' => 'consumer-secret'];
        $cases = [
            ['serpro_certificate_missing', [...$base, 'certificate' => null, 'certificate_password' => 'pfx-password']],
            ['serpro_certificate_missing', [...$base, 'certificate' => '', 'certificate_password' => 'pfx-password']],
            ['serpro_certificate_password_missing', [...$base, 'certificate' => base64_encode('pfx-bytes'), 'certificate_password' => null]],
            ['serpro_certificate_password_missing', [...$base, 'certificate' => base64_encode('pfx-bytes'), 'certificate_password' => '']],
        ];

        foreach ($cases as [$reason, $credentials]) {
            try {
                (new HttpOAuthMtlsTransport)->token($credentials, 'homologacao');
                $this->fail("Expected [{$reason}] to block the token exchange.");
            } catch (SerproBlockedException $exception) {
                $this->assertSame($reason, $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_token_requires_consumer_credentials_before_any_request(): void
    {
        Http::fake();

        $cases = [
            ['certificate' => base64_encode('pfx-bytes'), 'certificate_password' => 'pfx-password'],
            ['e_cnpj' => '179024', 'certificate' => base64_encode('pfx-bytes'), 'certificate_password' => 'pfx-password'],
            ['e_cnpj' => '   ', 'consumer_secret' => '', 'certificate' => base64_encode('pfx-bytes'), 'certificate_password' => 'pfx-password'],
        ];

        foreach ($cases as $credentials) {
            try {
                (new HttpOAuthMtlsTransport)->token($credentials, 'homologacao');
                $this->fail('Expected missing consumer credentials to block the token exchange.');
            } catch (SerproBlockedException $exception) {
                $this->assertSame('serpro_credential_invalid', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_token_rejects_certificate_that_is_neither_a_file_nor_base64(): void
    {
        Http::fake();

        $credentials = $this->credentials(certificate: 'not-base64-%%%');

        try {
            (new HttpOAuthMtlsTransport)->token($credentials, 'homologacao');
            $this->fail('Expected an unresolvable certificate to block the token exchange.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('serpro_certificate_invalid', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_token_writes_certificate_to_a_restricted_temp_file_and_removes_it_after_success(): void
    {
        $certificatePath = null;
        $certificatePermissions = null;
        $certificateBytes = null;
        Http::fake(function (Request $request, array $options) use (&$certificatePath, &$certificatePermissions, &$certificateBytes) {
            $certificatePath = $options['cert'][0] ?? null;
            if (is_string($certificatePath) && file_exists($certificatePath)) {
                $certificatePermissions = fileperms($certificatePath) & 0777;
                $certificateBytes = file_get_contents($certificatePath);
            }

            return Http::response(['access_token' => 'opaque'], 200);
        });

        (new HttpOAuthMtlsTransport)->token($this->credentials(), 'homologacao');

        $this->assertIsString($certificatePath);
        $this->assertSame(0600, $certificatePermissions);
        $this->assertSame('pfx-bytes', $certificateBytes);

        $this->assertFileDoesNotExist($certificatePath);
    }

    public function test_token_removes_temp_certificate_after_a_transport_failure(): void
    {
        $certificatePath = null;
        Http::fake(function (Request $request, array $options) use (&$certificatePath) {
            $certificatePath = $options['cert'][0] ?? null;

            throw new ConnectionException('connection reset by peer');
        });

        try {
            (new HttpOAuthMtlsTransport)->token($this->credentials(), 'homologacao');
            $this->fail('Expected the connection failure to bubble.');
        } catch (ConnectionException) {
            // expected
        }

        $this->assertIsString($certificatePath);
        $this->assertFileDoesNotExist($certificatePath);
    }

    public function test_token_resolves_certificate_path_into_a_temp_copy_without_touching_the_source(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'onefisc-cert-source-');
        $this->assertIsString($source);
        file_put_contents($source, 'source-pfx-bytes');

        $certificatePath = null;
        $tempBytes = null;
        Http::fake(function (Request $request, array $options) use (&$certificatePath, &$tempBytes) {
            $certificatePath = $options['cert'][0] ?? null;
            $tempBytes = is_string($certificatePath) ? file_get_contents($certificatePath) : null;

            return Http::response(['access_token' => 'opaque'], 200);
        });

        try {
            (new HttpOAuthMtlsTransport)->token($this->credentials(certificate: $source), 'homologacao');

            $this->assertIsString($certificatePath);
            $this->assertNotSame($source, $certificatePath);
            $this->assertSame('source-pfx-bytes', $tempBytes);
            $this->assertFileExists($source);
        } finally {
            @unlink($source);
            if (is_string($certificatePath)) {
                @unlink($certificatePath);
            }
        }

        $this->assertFileDoesNotExist($certificatePath);
    }

    public function test_token_returns_decoded_error_payload_for_rejected_exchange(): void
    {
        Http::fake(fn () => Http::response(['error' => 'invalid_client'], 401));

        $payload = (new HttpOAuthMtlsTransport)->token($this->credentials(), 'homologacao');

        $this->assertSame(['error' => 'invalid_client'], $payload);
        $this->assertArrayNotHasKey('status', $payload);
        $this->assertArrayNotHasKey('body', $payload);
    }

    public function test_token_returns_empty_payload_for_undecodable_response(): void
    {
        Http::fake(fn () => Http::response('gateway unavailable', 502));

        $payload = (new HttpOAuthMtlsTransport)->token($this->credentials(), 'homologacao');

        $this->assertSame([], $payload);
    }

    public function test_oauth_token_cache_consumes_the_real_transport_payload(): void
    {
        Cache::flush();
        Http::fake(fn () => Http::response([
            'access_token' => 'opaque-access-token',
            'expires_in' => 300,
            'jwt_token' => 'opaque-jwt',
        ], 200));

        $cache = new OAuthTokenCache(new HttpOAuthMtlsTransport);

        $tokens = $cache->get($this->credentialsObject(), 'homologacao', 'secret:contratante-e2e');

        $this->assertSame('opaque-access-token', $tokens['access_token']);
        $this->assertSame('opaque-jwt', $tokens['jwt_token']);
        Http::assertSentCount(1);
    }

    public function test_oauth_token_cache_fails_closed_when_exchange_is_rejected(): void
    {
        Cache::flush();
        Http::fake(fn () => Http::response(['error' => 'invalid_client'], 401));

        $cache = new OAuthTokenCache(new HttpOAuthMtlsTransport);

        $this->expectException(SerproBlockedException::class);
        $this->expectExceptionMessage('serpro_oauth_failed');

        $cache->get($this->credentialsObject(), 'homologacao', 'secret:contratante-e2e');
    }

    public function test_call_posts_envelope_with_bearer_token_and_idempotency_tag(): void
    {
        $captured = null;
        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = ['request' => $request, 'options' => $options];

            return Http::response(['protocolo' => 'abc-123'], 200);
        });

        $payload = (new HttpOAuthMtlsTransport)->call(
            '/Consultar',
            $this->envelope(),
            'opaque-access-token',
            ['idempotency_key' => 'request-tag-1'],
        );

        $this->assertSame(200, $payload['status']);
        $this->assertSame(['protocolo' => 'abc-123'], $payload['body']);

        $sent = $captured['request'];
        $this->assertSame('POST', $sent->method());
        $this->assertSame('https://gateway.example.test/integra-contador/v1/Consultar', $sent->url());
        $this->assertTrue($sent->hasHeader('Authorization', 'Bearer opaque-access-token'));
        $this->assertTrue($sent->hasHeader('X-Request-Tag', 'request-tag-1'));
        $this->assertFalse($sent->hasHeader('autenticar_procurador_token'));
        $this->assertFalse($sent->hasHeader('jwt_token'));
        $this->assertSame($this->envelope(), $sent->data());

        $this->assertSame(15, $captured['options']['timeout']);
        $this->assertSame(5, $captured['options']['connect_timeout']);
        $this->assertTrue($captured['options']['verify']);
        $this->assertArrayNotHasKey('cert', $captured['options']);
    }

    public function test_call_sends_procurador_and_jwt_headers_only_when_provided(): void
    {
        Http::fake();

        $transport = new HttpOAuthMtlsTransport;

        $transport->call('/Consultar', $this->envelope(), 'opaque-access-token', [
            'idempotency_key' => 'request-tag-2',
            'procurador_token' => 'procurador-token',
            'jwt_token' => 'jwt-token',
        ]);
        $transport->call('/Consultar', $this->envelope(), 'opaque-access-token', [
            'idempotency_key' => 'request-tag-3',
            'procurador_token' => null,
            'jwt_token' => '',
        ]);

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Request-Tag', 'request-tag-2')
            && $request->hasHeader('autenticar_procurador_token', 'procurador-token')
            && $request->hasHeader('jwt_token', 'jwt-token'));

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Request-Tag', 'request-tag-3')
            && ! $request->hasHeader('autenticar_procurador_token')
            && ! $request->hasHeader('jwt_token'));
    }

    public function test_call_requires_an_access_token_before_any_request(): void
    {
        Http::fake();

        try {
            (new HttpOAuthMtlsTransport)->call('/Consultar', $this->envelope(), '   ');
            $this->fail('Expected a missing access token to block the call.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('serpro_access_token_missing', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_call_returns_non_2xx_status_and_body_without_throwing(): void
    {
        Http::fake(fn () => Http::response(['mensagem' => 'requisicao invalida'], 422));

        $payload = (new HttpOAuthMtlsTransport)->call('/Consultar', $this->envelope(), 'opaque-access-token');

        $this->assertSame(422, $payload['status']);
        $this->assertSame(['mensagem' => 'requisicao invalida'], $payload['body']);
    }

    public function test_non_json_body_is_returned_as_an_empty_array(): void
    {
        Http::fake(fn () => Http::response('service unavailable', 503));

        $payload = (new HttpOAuthMtlsTransport)->call('/Consultar', $this->envelope(), 'opaque-access-token');

        $this->assertSame(503, $payload['status']);
        $this->assertSame([], $payload['body']);
    }

    public function test_call_joins_base_url_and_path_with_a_single_slash(): void
    {
        config()->set('monitoring.base_url', 'https://gateway.example.test/integra-contador/v1/');
        Http::fake();

        $transport = new HttpOAuthMtlsTransport;
        $transport->call('Apoiar', $this->envelope(), 'opaque-access-token');
        $transport->call('/Apoiar', $this->envelope(), 'opaque-access-token');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.example.test/integra-contador/v1/Apoiar');
    }

    public function test_contract_binding_resolves_to_the_http_transport(): void
    {
        $this->assertInstanceOf(HttpOAuthMtlsTransport::class, app(SerproTransport::class));
    }

    /**
     * @return array{e_cnpj: string, consumer_secret: string, certificate: string, certificate_password: string}
     */
    private function credentials(?string $certificate = null, ?string $password = 'pfx-password'): array
    {
        return [
            'e_cnpj' => '179024',
            'consumer_secret' => 'consumer-secret',
            'certificate' => $certificate ?? base64_encode('pfx-bytes'),
            'certificate_password' => $password,
        ];
    }

    private function credentialsObject(): SerproCredentials
    {
        return new SerproCredentials(
            eCnpj: '179024',
            consumerSecret: 'consumer-secret',
            contratanteDoc: '65396736000176',
            certificate: base64_encode('pfx-bytes'),
            certificatePassword: 'pfx-password',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            'contratante' => ['numero' => '65396736000176', 'tipo' => 2],
            'autorPedidoDados' => ['numero' => '65396736000176', 'tipo' => 2],
            'contribuinte' => ['numero' => '12345678901', 'tipo' => 1],
            'pedidoDados' => [
                'idSistema' => 'PGDASD',
                'idServico' => 'CONSDECLARACAO13',
                'versaoSistema' => '1',
                'dados' => '{}',
            ],
        ];
    }
}
