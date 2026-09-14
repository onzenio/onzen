<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Models\SerproContract;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerproCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    private function platformAccount()
    {
        return $this->createAccount(['profile' => AccountProfile::A]);
    }

    private function contractWithVault($platform, string $key = 'KEY-1', string $secret = 'SEC-1'): SerproContract
    {
        $vault = app(VaultService::class);
        $keyRef = $vault->put($platform, 'consumer-key', $key);
        $secretRef = $vault->put($platform, 'consumer-secret', $secret);

        return SerproContract::factory()->create([
            'environment' => 'homologacao',
            'consumer_key_ref' => $keyRef,
            'consumer_secret_ref' => $secretRef,
        ]);
    }

    public function test_resolve_consumer_no_escopo_da_plataforma(): void
    {
        $platform = $this->platformAccount();
        $this->contractWithVault($platform);

        $consumer = app(SerproCredentialResolver::class)->resolveConsumer($platform);

        $this->assertSame(['key' => 'KEY-1', 'secret' => 'SEC-1'], $consumer);
    }

    public function test_sem_contrato_ou_ref_resolve_nulo(): void
    {
        $platform = $this->platformAccount();
        $resolver = app(SerproCredentialResolver::class);

        $this->assertNull($resolver->resolveConsumer($platform));

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'consumer_key_ref' => 'secret:999999:inexistente',
            'consumer_secret_ref' => 'secret:999999:inexistente',
        ]);

        $this->assertNull($resolver->resolveConsumer($platform));
        $this->assertNull($resolver->token($platform));
    }

    public function test_token_com_cache_expiracao_e_renovacao(): void
    {
        $platform = $this->platformAccount();
        $this->contractWithVault($platform);
        $resolver = app(SerproCredentialResolver::class);

        Http::fake([
            '*' => Http::sequence()
                ->push(['access_token' => 'TOKEN-1', 'expires_in' => 3000], 200)
                ->push(['access_token' => 'TOKEN-2'], 200),
        ]);

        $this->assertSame('TOKEN-1', $resolver->token($platform));
        // Segunda chamada usa o cache: sem novo HTTP.
        $this->assertSame('TOKEN-1', $resolver->token($platform));
        Http::assertSentCount(1);

        // Limpeza (expiração/401-403) força renovação.
        $resolver->clearToken();
        $this->assertNull(Cache::get(SerproCredentialResolver::cacheKey('homologacao')));

        $this->assertSame('TOKEN-2', $resolver->token($platform));
        Http::assertSentCount(2);
    }

    public function test_falha_de_token_retorna_nulo_sem_cache(): void
    {
        $platform = $this->platformAccount();
        $this->contractWithVault($platform);
        $resolver = app(SerproCredentialResolver::class);

        Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->assertNull($resolver->token($platform));
        $this->assertNull(Cache::get(SerproCredentialResolver::cacheKey('homologacao')));
    }
}
