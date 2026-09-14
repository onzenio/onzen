<?php

namespace Tests\Feature;

use App\Models\AccountCertificate;
use App\Services\AccountCertificateService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('account_certificates', [
            'id', 'account_id', 'pfx_ref', 'password_ref', 'holder_name', 'thumbprint', 'expires_at',
        ]));
    }

    public function test_register_cadastro_valido_sem_expor_secreto(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        $service = app(AccountCertificateService::class);

        $cert = $service->register($account, [
            'pfx_ref' => 'secret:PFX1111',
            'password_ref' => 'secret:PWD2222',
            'holder_name' => 'Empresa LTDA',
            'thumbprint' => str_repeat('a', 40),
            'expires_at' => now()->addYear(),
        ], $actor);

        $this->assertDatabaseHas('account_certificates', [
            'id' => $cert->id,
            'account_id' => $account->id,
        ]);
        $masked = $cert->toMaskedArray();
        $this->assertStringNotContainsString('PFX1111', json_encode($masked));
        $this->assertStringNotContainsString('PWD2222', json_encode($masked));
        $this->assertSame(str_repeat('a', 40), $masked['thumbprint']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_certificate.registered',
            'target_account_id' => $account->id,
        ]);
    }

    public function test_register_invalido_mantem_anterior(): void
    {
        $account = $this->createAccount();
        $service = app(AccountCertificateService::class);
        $original = $service->register($account, [
            'pfx_ref' => 'secret:OLD',
            'password_ref' => 'secret:OLDPWD',
            'holder_name' => 'Empresa LTDA',
            'thumbprint' => str_repeat('b', 40),
            'expires_at' => now()->addYear(),
        ]);

        try {
            $service->register($account, [
                'pfx_ref' => '',
                'password_ref' => '',
                'holder_name' => 'X',
                'thumbprint' => 'y',
                'expires_at' => now()->addYear(),
            ]);
            $this->fail('Deveria ter lançado ValidationException');
        } catch (ValidationException) {
        }

        $this->assertSame('secret:OLD', $original->refresh()->getAttributes()['pfx_ref']);
    }

    public function test_substituicao_mantem_unica_versao_e_audita(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        $service = app(AccountCertificateService::class);

        $service->register($account, [
            'pfx_ref' => 'secret:V1',
            'password_ref' => 'secret:P1',
            'holder_name' => 'Empresa LTDA',
            'thumbprint' => str_repeat('c', 40),
            'expires_at' => now()->addYear(),
        ], $actor);

        $service->register($account, [
            'pfx_ref' => 'secret:V2',
            'password_ref' => 'secret:P2',
            'holder_name' => 'Empresa LTDA',
            'thumbprint' => str_repeat('d', 40),
            'expires_at' => now()->addYear(),
        ], $actor);

        $this->assertSame(1, AccountCertificate::query()->withoutGlobalScopes()->where('account_id', $account->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_certificate.replaced']);
    }

    public function test_isolamento_entre_accounts(): void
    {
        $a = $this->createAccount();
        $b = $this->createAccount();
        $service = app(AccountCertificateService::class);

        $service->register($a, [
            'pfx_ref' => 'secret:A', 'password_ref' => 'secret:PA',
            'holder_name' => 'A LTDA', 'thumbprint' => str_repeat('e', 40),
            'expires_at' => now()->addYear(),
        ]);
        $service->register($b, [
            'pfx_ref' => 'secret:B', 'password_ref' => 'secret:PB',
            'holder_name' => 'B LTDA', 'thumbprint' => str_repeat('f', 40),
            'expires_at' => now()->addYear(),
        ]);

        CurrentAccount::set($a->id);
        $this->assertSame(1, AccountCertificate::query()->count());
        $this->assertTrue(AccountCertificate::query()->firstOrFail()->account->is($a));

        CurrentAccount::set($b->id);
        $this->assertSame(1, AccountCertificate::query()->count());
        $this->assertTrue(AccountCertificate::query()->firstOrFail()->account->is($b));

        CurrentAccount::clear();
    }
}
