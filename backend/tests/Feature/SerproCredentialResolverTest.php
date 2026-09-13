<?php

namespace Tests\Feature;

use App\Contracts\VaultResolver;
use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\OAuthTokenCache;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Integrations\Serpro\SerproCredentials;
use App\Models\SerproContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

final class SerproCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    private VaultResolver $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = app(VaultResolver::class);
        Cache::flush();
    }

    public function test_resolves_json_payload_with_client_id_and_contratante_doc(): void
    {
        $contract = $this->contractWithCredential((string) json_encode([
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => '65.396.736/0001-76',
        ]));

        $credentials = (new SerproCredentialResolver($this->vault))->resolve($contract);

        $this->assertSame('179024', $credentials->eCnpj);
        $this->assertSame('consumer-secret', $credentials->consumerSecret);
        $this->assertSame('65.396.736/0001-76', $credentials->contratanteDoc);
    }

    public function test_resolves_json_payload_with_e_cnpj_alias_and_falls_back_to_it_as_contratante_doc(): void
    {
        $contract = $this->contractWithCredential((string) json_encode([
            'e_cnpj' => '98765432000199',
            'consumer_secret' => 'alias-secret',
        ]));

        $credentials = (new SerproCredentialResolver($this->vault))->resolve($contract);

        $this->assertSame('98765432000199', $credentials->eCnpj);
        $this->assertSame('alias-secret', $credentials->consumerSecret);
        $this->assertSame('98765432000199', $credentials->contratanteDoc);
    }

    public function test_resolves_legacy_pair_form(): void
    {
        $contract = $this->contractWithCredential('98765432000199:pair-secret');

        $credentials = (new SerproCredentialResolver($this->vault))->resolve($contract);

        $this->assertSame('98765432000199', $credentials->eCnpj);
        $this->assertSame('pair-secret', $credentials->consumerSecret);
        $this->assertSame('98765432000199', $credentials->contratanteDoc);
    }

    public function test_rejects_credential_ref_that_is_not_an_opaque_secret_ref(): void
    {
        $resolver = new SerproCredentialResolver($this->vault);

        foreach (['plain-ref', 'vault:123', ''] as $ref) {
            try {
                $resolver->resolveRef($ref);
                $this->fail("Expected ref [{$ref}] to be rejected.");
            } catch (SerproBlockedException $exception) {
                $this->assertSame('serpro_credential_ref_invalid', $exception->getMessage());
            }
        }
    }

    public function test_fails_closed_when_contract_or_ref_is_missing(): void
    {
        $resolver = new SerproCredentialResolver($this->vault);

        try {
            $resolver->resolve(null);
            $this->fail('Expected a missing contract to be rejected.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('serpro_credential_missing', $exception->getMessage());
        }

        $contract = SerproContract::factory()->create(['credential_ref' => null]);

        try {
            $resolver->resolve($contract);
            $this->fail('Expected a null credential ref to be rejected.');
        } catch (SerproBlockedException $exception) {
            $this->assertSame('serpro_credential_ref_invalid', $exception->getMessage());
        }
    }

    public function test_fails_closed_when_vault_entry_does_not_exist(): void
    {
        $contract = SerproContract::factory()->create(['credential_ref' => 'secret:missing-credential']);

        $this->expectException(SerproBlockedException::class);
        $this->expectExceptionMessage('serpro_credential_unresolved');

        (new SerproCredentialResolver($this->vault))->resolve($contract);
    }

    public function test_fails_closed_when_payload_has_no_consumer_credentials(): void
    {
        $resolver = new SerproCredentialResolver($this->vault);

        foreach (['{}', '{"client_id":"1"}', '{"consumer_secret":"only-secret"}', 'only-one-part', ''] as $payload) {
            $this->vault->put('secret:invalid-payload', $payload);

            try {
                $resolver->resolveRef('secret:invalid-payload');
                $this->fail("Expected payload [{$payload}] to be rejected.");
            } catch (SerproBlockedException $exception) {
                $this->assertSame('serpro_credential_invalid', $exception->getMessage());
            }
        }
    }

    public function test_credentials_never_expose_secrets_through_serialization(): void
    {
        $credentials = new SerproCredentials(
            eCnpj: '179024',
            consumerSecret: 'consumer-secret',
            contratanteDoc: '65396736000176',
            certificate: 'pfx-bytes',
            certificatePassword: 'pfx-password',
        );

        foreach ([serialize($credentials), (string) json_encode($credentials)] as $serialized) {
            $this->assertStringNotContainsString('consumer-secret', $serialized);
            $this->assertStringNotContainsString('pfx-bytes', $serialized);
            $this->assertStringNotContainsString('pfx-password', $serialized);
            $this->assertStringContainsString('resolved', $serialized);
        }

        $this->assertSame(
            ['e_cnpj' => '179024', 'consumer_secret' => 'consumer-secret', 'certificate' => 'pfx-bytes', 'certificate_password' => 'pfx-password'],
            $credentials->toTransportCredentials(),
        );
    }

    public function test_token_cache_reuses_cached_access_token(): void
    {
        $transport = new FakeSerproTransport([
            ['access_token' => 'opaque-token', 'expires_in' => 300, 'jwt_token' => 'opaque-jwt'],
        ]);
        $cache = new OAuthTokenCache($transport);
        $credentials = new SerproCredentials('179024', 'consumer-secret', '65396736000176');

        $first = $cache->get($credentials, 'homologacao', 'secret:contratante');
        $second = $cache->get($credentials, 'homologacao', 'secret:contratante');

        $this->assertSame('opaque-token', $first['access_token']);
        $this->assertSame('opaque-jwt', $first['jwt_token']);
        $this->assertSame($first, $second);
        $this->assertCount(1, $transport->tokenCalls);
        $this->assertSame('179024', $transport->tokenCalls[0]['credentials']['e_cnpj']);
        $this->assertSame('homologacao', $transport->tokenCalls[0]['environment']);
    }

    public function test_token_cache_expires_after_expires_in_minus_thirty_seconds(): void
    {
        $transport = new FakeSerproTransport([
            ['access_token' => 'token-one', 'expires_in' => 120],
            ['access_token' => 'token-two', 'expires_in' => 120],
        ]);
        $cache = new OAuthTokenCache($transport);
        $credentials = new SerproCredentials('179024', 'consumer-secret', '65396736000176');

        $cache->get($credentials, 'homologacao', 'secret:contratante');

        $this->travel(89)->seconds();
        $this->assertSame(
            'token-one',
            $cache->get($credentials, 'homologacao', 'secret:contratante')['access_token'],
        );

        $this->travel(2)->seconds();
        $this->assertSame(
            'token-two',
            $cache->get($credentials, 'homologacao', 'secret:contratante')['access_token'],
        );
        $this->assertCount(2, $transport->tokenCalls);
    }

    public function test_token_cache_fails_closed_without_access_token(): void
    {
        $transport = new FakeSerproTransport([['expires_in' => 300]]);
        $cache = new OAuthTokenCache($transport);
        $credentials = new SerproCredentials('179024', 'consumer-secret', '65396736000176');

        $this->expectException(SerproBlockedException::class);
        $this->expectExceptionMessage('serpro_oauth_failed');

        $cache->get($credentials, 'homologacao', 'secret:contratante');
    }

    public function test_token_cache_drops_stale_jwt_token(): void
    {
        $transport = new FakeSerproTransport([
            ['access_token' => 'token-one', 'expires_in' => 120, 'jwt_token' => 'jwt-one'],
            ['access_token' => 'token-two', 'expires_in' => 120],
        ]);
        $cache = new OAuthTokenCache($transport);
        $credentials = new SerproCredentials('179024', 'consumer-secret', '65396736000176');

        $this->assertSame(
            'jwt-one',
            $cache->get($credentials, 'homologacao', 'secret:contratante')['jwt_token'],
        );

        $this->travel(91)->seconds();

        $refreshed = $cache->get($credentials, 'homologacao', 'secret:contratante');

        $this->assertSame('token-two', $refreshed['access_token']);
        $this->assertNull($refreshed['jwt_token']);
    }

    public function test_token_cache_forgets_tokens_on_401_and_403_only(): void
    {
        $transport = new FakeSerproTransport([
            ['access_token' => 'token-one', 'expires_in' => 300, 'jwt_token' => 'jwt-one'],
            ['access_token' => 'token-two', 'expires_in' => 300],
        ]);
        $cache = new OAuthTokenCache($transport);
        $credentials = new SerproCredentials('179024', 'consumer-secret', '65396736000176');

        $cache->get($credentials, 'homologacao', 'secret:contratante');
        $this->assertFalse($cache->forgetIfUnauthorized(500, 'homologacao', 'secret:contratante'));
        $this->assertSame(
            'token-one',
            $cache->get($credentials, 'homologacao', 'secret:contratante')['access_token'],
        );

        $this->assertTrue($cache->forgetIfUnauthorized(403, 'homologacao', 'secret:contratante'));
        $this->assertSame(
            'token-two',
            $cache->get($credentials, 'homologacao', 'secret:contratante')['access_token'],
        );

        $this->assertTrue($cache->forgetIfUnauthorized(401, 'homologacao', 'secret:contratante'));
        $this->assertCount(2, $transport->tokenCalls);
    }

    public function test_token_cache_is_scoped_per_environment_and_credential_ref(): void
    {
        $transport = new FakeSerproTransport([
            ['access_token' => 'token-homolog', 'expires_in' => 300],
            ['access_token' => 'token-prod', 'expires_in' => 300],
            ['access_token' => 'token-rotated', 'expires_in' => 300],
        ]);
        $cache = new OAuthTokenCache($transport);
        $credentials = new SerproCredentials('179024', 'consumer-secret', '65396736000176');

        $this->assertSame('token-homolog', $cache->get($credentials, 'homologacao', 'secret:contratante')['access_token']);
        $this->assertSame('token-prod', $cache->get($credentials, 'producao', 'secret:contratante')['access_token']);
        $this->assertSame('token-rotated', $cache->get($credentials, 'homologacao', 'secret:contratante-v2')['access_token']);
        $this->assertCount(3, $transport->tokenCalls);
    }

    private function contractWithCredential(string $payload): SerproContract
    {
        $ref = 'secret:contratante-'.uniqid();
        $this->vault->put($ref, $payload);

        return SerproContract::factory()->create(['credential_ref' => $ref]);
    }
}
