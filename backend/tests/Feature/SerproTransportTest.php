<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Integrations\Serpro\SerproTransport;
use App\Integrations\Serpro\SerproTransportException;
use App\Models\SerproContract;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerproTransportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: \App\Models\Account, 1: SerproTransport}
     */
    private function liveTransport(): array
    {
        $platform = $this->createAccount(['profile' => AccountProfile::A]);
        $vault = app(VaultService::class);

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'transport_approved' => true,
            'consumer_key_ref' => $vault->put($platform, 'consumer-key', 'KEY'),
            'consumer_secret_ref' => $vault->put($platform, 'consumer-secret', 'SEC'),
        ]);

        Config::set('monitoring.dry_run', false);
        Config::set('monitoring.transport.approved', true);

        return [$platform, app(SerproTransport::class)];
    }

    private function tempLeftovers(): array
    {
        $files = glob(sys_get_temp_dir().'/'.SerproTransport::TEMP_PREFIX.'*');

        return $files === false ? [] : $files;
    }

    public function test_envia_headers_e_mtls_e_remove_cert_em_sucesso(): void
    {
        [$platform, $transport] = $this->liveTransport();
        $this->assertSame([], $this->tempLeftovers());

        Http::fake([
            config('monitoring.token_url').'*' => Http::response(['access_token' => 'TOKEN'], 200),
            '*' => Http::response(['ok' => true, 'protocolo' => 'P1'], 200),
        ]);

        $result = $transport->request('consultar-sitfis', ['pedido' => ['operacao' => 'consultar-sitfis']], [
            'platform_account' => $platform,
            'idempotency_key' => 'TAG-1',
            'procurador_token' => 'PROC-1',
            'pfx_contents' => 'PFX-BIN',
            'pfx_password' => 'PWD',
        ]);

        $this->assertSame('live', $result['transport']);
        $this->assertSame([], $this->tempLeftovers());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'consultar/consultar-sitfis')
                && $request->header('Authorization') === ['Bearer TOKEN']
                && $request->header('X-Request-Tag') === ['TAG-1']
                && $request->header('autenticar_procurador_token') === ['PROC-1']
                && $request->header('Role-Type') !== [];
        });
    }

    public function test_remove_cert_em_falha(): void
    {
        [$platform, $transport] = $this->liveTransport();

        Http::fake([
            config('monitoring.token_url').'*' => Http::response(['access_token' => 'TOKEN'], 200),
            '*' => Http::response(['erro' => 'x'], 500),
        ]);

        try {
            $transport->request('consultar-sitfis', [], [
                'platform_account' => $platform,
                'pfx_contents' => 'PFX-BIN',
            ]);
            $this->fail('Deveria lançar em falha 500');
        } catch (SerproTransportException) {
        }

        $this->assertSame([], $this->tempLeftovers());
    }

    public function test_401_limpa_token_e_falha(): void
    {
        [$platform, $transport] = $this->liveTransport();

        Http::fake([
            config('monitoring.token_url').'*' => Http::response(['access_token' => 'TOKEN'], 200),
            '*' => Http::response(['erro' => 'auth'], 401),
        ]);

        try {
            $transport->request('consultar-sitfis', [], [
                'platform_account' => $platform,
                'pfx_contents' => 'PFX-BIN',
            ]);
            $this->fail('Deveria lançar em 401');
        } catch (SerproTransportException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
        }

        $this->assertSame([], $this->tempLeftovers());
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('serpro:token:homologacao'));
    }

    public function test_dry_run_usa_fixture_sem_http(): void
    {
        $platform = $this->createAccount(['profile' => AccountProfile::A]);

        Config::set('monitoring.dry_run', true);
        Config::set('monitoring.transport.approved', false);
        Http::preventStrayRequests();

        $result = app(SerproTransport::class)->request('consultar-sitfis', [], [
            'platform_account' => $platform,
        ]);

        $this->assertSame('dry-run', $result['transport']);
        $this->assertSame('consultar-sitfis', $result['operation']);
        Http::assertSentCount(0);
    }
}
