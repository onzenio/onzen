<?php

namespace Tests\Feature;

use App\Integrations\Serpro\ProcuradorTermSender;
use App\Models\SerproRequestAuthor;
use App\Services\AccountCertificateService;
use App\Services\ProcuradorTermService;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcuradorTermTest extends TestCase
{
    use RefreshDatabase;

    private function selfSignedPfx(string $password): ?string
    {
        if (! function_exists('openssl_pkey_new')) {
            return null;
        }

        if (getenv('OPENSSL_CONF') === false && is_file('/etc/ssl/openssl.cnf')) {
            putenv('OPENSSL_CONF=/etc/ssl/openssl.cnf');
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            return null;
        }

        $csr = openssl_csr_new(['CN' => 'Empresa Teste LTDA'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);

        if (! openssl_pkcs12_export($cert, $p12, $key, $password)) {
            return null;
        }

        return $p12;
    }

    /**
     * @return array{0: \App\Models\Account, 1: SerproRequestAuthor, 2: object}
     */
    private function authorWithCert(string $pfx, string $password): array
    {
        $account = $this->createAccount();
        $vault = app(VaultService::class);

        $pfxRef = $vault->put($account, 'pfx', $pfx);
        $pwdRef = $vault->put($account, 'pfx-password', $password);

        \App\Models\AccountCertificate::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'pfx_ref' => $pfxRef,
            'password_ref' => $pwdRef,
            'holder_name' => 'Empresa Teste LTDA',
            'thumbprint' => str_repeat('a', 40),
            'expires_at' => now()->addYear(),
        ]);

        $author = app(\App\Services\SerproRequestAuthorService::class)->register($account, [
            'document' => '12345678901',
            'name' => 'Procurador',
        ]);

        $sender = new class implements ProcuradorTermSender
        {
            public int $calls = 0;

            public function send(array $signedTerm): array
            {
                $this->calls++;

                assert(isset($signedTerm['assinatura']));

                return ['token' => 'TOKEN-'.$this->calls, 'expires_at' => now()->addMonths(6)->toIso8601String()];
            }
        };

        $this->app->instance(ProcuradorTermSender::class, $sender);

        return [$account, $author, $sender];
    }

    public function test_termo_assinado_enviado_e_token_guardado_no_cofre(): void
    {
        $pfx = $this->selfSignedPfx('senha123');

        if ($pfx === null) {
            $this->markTestSkipped('OpenSSL indisponível para gerar PFX de teste.');
        }

        [$account, $author, $sender] = $this->authorWithCert($pfx, 'senha123');

        app(ProcuradorTermService::class)->ensureToken($account, $author->refresh());

        $this->assertSame(1, $sender->calls);
        $this->assertNotNull($author->refresh()->token_ref);
        $this->assertSame('TOKEN-1', app(VaultService::class)->get($author->refresh()->token_ref));
        $this->assertDatabaseHas('audit_logs', ['action' => 'serpro_author.token_renewed']);

        // Segunda chamada reutiliza o token: sem novo envio.
        app(ProcuradorTermService::class)->ensureToken($account, $author->refresh());
        $this->assertSame(1, $sender->calls);
    }

    public function test_token_proximo_do_vencimento_e_renovado(): void
    {
        $pfx = $this->selfSignedPfx('senha123');

        if ($pfx === null) {
            $this->markTestSkipped('OpenSSL indisponível para gerar PFX de teste.');
        }

        [$account, $author, $sender] = $this->authorWithCert($pfx, 'senha123');
        $service = app(ProcuradorTermService::class);

        $service->ensureToken($account, $author->refresh());

        $author->refresh()->forceFill(['token_expires_at' => now()->addDay()])->save();
        $service->ensureToken($account, $author->refresh());

        $this->assertSame(2, $sender->calls);
        $this->assertSame('TOKEN-2', app(VaultService::class)->get($author->refresh()->token_ref));
    }

    public function test_token_nunca_aparece_em_resposta_ou_audit(): void
    {
        $account = $this->createAccount();
        app(AccountCertificateService::class)->register($account, [
            'pfx_ref' => 'secret:X', 'password_ref' => 'secret:Y',
            'holder_name' => 'E', 'thumbprint' => str_repeat('b', 40),
            'expires_at' => now()->addYear(),
        ]);
        $author = SerproRequestAuthor::factory()->create([
            'account_id' => $account->id,
            'token_ref' => "secret:{$account->id}:procurador-token-1",
        ]);
        app(VaultService::class)->put($account, 'procurador-token-1', 'TOKEN-SUPER-SECRETO');

        $masked = $author->refresh()->toMaskedArray();
        $this->assertStringNotContainsString('TOKEN-SUPER-SECRETO', json_encode($masked));

        $audits = json_encode(\App\Models\AuditLog::query()->pluck('metadata')->all());
        $this->assertStringNotContainsString('TOKEN-SUPER-SECRETO', $audits);
    }
}
