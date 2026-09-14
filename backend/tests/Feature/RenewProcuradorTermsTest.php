<?php

namespace Tests\Feature;

use App\Integrations\Serpro\ProcuradorTermSender;
use App\Integrations\Serpro\SerproTermSigner;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Models\Plan;
use App\Services\EnrollmentService;
use App\Services\ProcurationChecker;
use App\Services\SerproRequestAuthorService;
use App\Services\VaultService;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewProcuradorTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_comando_renova_e_retoma_elegiveis(): void
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $sender = new class implements ProcuradorTermSender
        {
            public int $calls = 0;

            public function send(array $signedTerm): array
            {
                $this->calls++;

                return ['token' => 'TOKEN-NOVO', 'expires_at' => now()->addMonths(6)->toIso8601String()];
            }
        };
        $this->app->instance(ProcuradorTermSender::class, $sender);

        $checker = new class implements ProcurationChecker
        {
            public function granted(Client $client, string $serviceCode): bool
            {
                return true;
            }
        };
        $this->app->instance(ProcurationChecker::class, $checker);

        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['max_clients' => 10])->id])->save();
        $vault = app(VaultService::class);

        $signer = \Mockery::mock(SerproTermSigner::class);
        $signer->shouldReceive('canonical')->andReturn('canonico');
        $signer->shouldReceive('sign')->andReturn('ASSINATURA');
        $this->app->instance(SerproTermSigner::class, $signer);
        $pfxRef = $vault->put($account, 'pfx', 'PFX');
        $pwdRef = $vault->put($account, 'pfx-password', 'PWD');
        AccountCertificate::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'pfx_ref' => $pfxRef,
            'password_ref' => $pwdRef,
            'holder_name' => 'E',
            'thumbprint' => str_repeat('a', 40),
            'expires_at' => now()->addYear(),
        ]);

        $author = app(SerproRequestAuthorService::class)->register($account, [
            'document' => '12345678901', 'name' => 'P',
        ]);
        $author->forceFill([
            'token_ref' => $vault->put($account, 'procurador-token-1', 'TOKEN-ANTIGO'),
            'token_expires_at' => now()->addDay(),
        ])->save();

        $client = Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => true, 'regime' => 'simples',
        ]);
        $enrollment = app(EnrollmentService::class)->create($account, $client->id, 'sitfis');
        $enrollment->pause('outorga pendente');

        $this->artisan('monitoring:renew-terms')->assertSuccessful();

        // Termo renovado antes do vencimento e guardado no cofre.
        $this->assertSame(1, $sender->calls);
        $this->assertSame('TOKEN-NOVO', $vault->get($author->refresh()->token_ref));

        // Associação pausada por outorga, agora elegível, retomada.
        $this->assertSame(MonitoringEnrollment::ACTIVE, $enrollment->refresh()->status);
    }
}
