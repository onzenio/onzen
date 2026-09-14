<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountCertificate;
use App\Models\SerproRequestAuthor;
use App\Policies\SerproRequestAuthorPolicy;
use App\Services\AccountCertificateService;
use App\Services\SerproRequestAuthorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SerproRequestAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('serpro_request_authors', [
            'id', 'account_id', 'account_certificate_id', 'document', 'name', 'status',
        ]));
    }

    private function registerCertificate($account, bool $expired = false): void
    {
        app(AccountCertificateService::class)->register($account, [
            'pfx_ref' => 'secret:PFX',
            'password_ref' => 'secret:PWD',
            'holder_name' => 'Empresa LTDA',
            'thumbprint' => str_repeat('a', 40),
            'expires_at' => $expired ? now()->subDay() : now()->addYear(),
        ]);
    }

    public function test_cadastro_vincula_thumbprint_do_certificado(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $this->registerCertificate($account);

        $author = app(SerproRequestAuthorService::class)->register($account, [
            'document' => '12345678901',
            'name' => 'Procurador',
        ], $actor);

        $this->assertSame(str_repeat('a', 40), $author->toMaskedArray()['certificate_thumbprint']);
        $this->assertTrue($author->isEligible());
        $this->assertDatabaseHas('audit_logs', ['action' => 'serpro_author.registered']);
    }

    public function test_certificado_expirado_torna_autor_inelegivel(): void
    {
        $account = $this->createAccount();
        $this->registerCertificate($account);
        $service = app(SerproRequestAuthorService::class);

        $author = $service->register($account, ['document' => '12345678901', 'name' => 'P']);
        $this->assertTrue($author->isEligible());

        $account->certificates ?? null;
        AccountCertificate::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->assertFalse($author->refresh()->isEligible());

        $affected = $service->syncEligibility($account);
        $this->assertSame(1, $affected);
        $this->assertSame(SerproRequestAuthor::STATUS_INELIGIBLE, $author->refresh()->status);
    }

    public function test_sem_certificado_cadastro_e_recusado(): void
    {
        $account = $this->createAccount();

        $this->expectException(ValidationException::class);

        app(SerproRequestAuthorService::class)->register($account, [
            'document' => '12345678901', 'name' => 'P',
        ]);
    }

    public function test_permissoes_por_role(): void
    {
        $account = $this->createAccount();
        $this->registerCertificate($account);
        $author = app(SerproRequestAuthorService::class)->register($account, [
            'document' => '12345678901', 'name' => 'P',
        ]);

        $policy = app(SerproRequestAuthorPolicy::class);

        foreach ([UserRole::SuperAdmin, UserRole::Admin] as $role) {
            $user = $this->createUser($account, ['role' => $role]);
            $this->assertTrue($policy->create($user), $role->value.' deveria gerenciar autores');
            $this->assertTrue($policy->update($user, $author));
        }

        foreach ([UserRole::Operator, UserRole::User] as $role) {
            $user = $this->createUser($account, ['role' => $role]);
            $this->assertFalse($policy->create($user), $role->value.' não deveria gerenciar autores');
            $this->assertFalse($policy->update($user, $author));
        }
    }
}
