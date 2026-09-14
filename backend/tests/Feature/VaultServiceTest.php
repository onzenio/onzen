<?php

namespace Tests\Feature;

use App\Models\VaultSecret;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VaultServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('vault_secrets', [
            'id', 'account_id', 'name', 'ciphertext',
        ]));
    }

    public function test_valor_e_cifrado_no_banco_e_legivel_pelo_cofre(): void
    {
        $account = $this->createAccount();
        $service = app(VaultService::class);

        $ref = $service->put($account, 'consumer-secret', 'SUPER-SECRETO-123');

        $this->assertTrue(str_starts_with($ref, 'secret:'));
        $this->assertStringNotContainsString('SUPER-SECRETO-123', $ref);

        $raw = VaultSecret::query()->withoutGlobalScopes()->firstOrFail()->getAttributes()['ciphertext'];
        $this->assertStringNotContainsString('SUPER-SECRETO-123', $raw);

        $this->assertSame('SUPER-SECRETO-123', $service->get($ref));
    }

    public function test_substituicao_torna_valor_antigo_inacessivel(): void
    {
        $account = $this->createAccount();
        $service = app(VaultService::class);

        $ref = $service->put($account, 'pfx', 'BINARIO-V1');
        $service->put($account, 'pfx', 'BINARIO-V2');

        $this->assertSame(1, VaultSecret::query()->withoutGlobalScopes()->where('account_id', $account->id)->count());
        $this->assertSame('BINARIO-V2', $service->get($ref));
    }

    public function test_escopo_por_account_ref_de_outra_conta_nao_resolve(): void
    {
        $a = $this->createAccount();
        $service = app(VaultService::class);

        $ref = $service->put($a, 'consumer-secret', 'SEGREDO-A');

        // Ref adulterada para outra account não resolve.
        $this->assertNull($service->get('secret:999999:consumer-secret'));
        $this->assertSame('SEGREDO-A', $service->get($ref));
        $this->assertNull($service->get('not-a-ref'));
    }

    public function test_serializacao_e_redaction_sem_segredo(): void
    {
        $account = $this->createAccount();
        $service = app(VaultService::class);
        $service->put($account, 'consumer-secret', 'SEGREDO');

        $secret = VaultSecret::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertArrayNotHasKey('ciphertext', $secret->toArray());

        $redacted = $service->redact([
            'consumer_secret_ref' => "secret:{$account->id}:consumer-secret",
            'token' => 'abc123',
            'environment' => 'homologacao',
        ]);

        $this->assertSame('secret:***', $redacted['consumer_secret_ref']);
        $this->assertSame('***', $redacted['token']);
        $this->assertSame('homologacao', $redacted['environment']);
    }
}
