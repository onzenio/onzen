<?php

namespace Tests\Feature;

use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Enums\AuthorDocumentType;
use App\Enums\AuthorStatus;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\PowerOfAttorney;
use App\Models\SerproContract;
use App\Models\SerproRequestAuthor;
use App\Models\SerproSettings;
use App\Services\Monitoring\ProcuradorTermService;
use App\Services\Monitoring\ProcurationVerifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Support\FakeSerproTransport;
use Tests\TestCase;

/**
 * Daily warm-up routine (Task 21 / design decision 6): renews
 * Autentica-Procurador terms near expiry, re-verifies outorgas of Clients
 * with paused-by-outorga associations or grants near expiry and resumes the
 * paused associations through the Task 20 path. Preview by default
 * (`--confirm` to touch the wire); fail-closed with the transport closed.
 */
final class WarmProcuracoesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRATANTE_DOCUMENT = '65.396.736/0001-76';

    private const AUTHOR_DOCUMENT = '12345678000195';

    private const CLIENT_CNPJ = '11222333000181';

    private const TOKEN = 'TOKEN-RENEWED';

    private const EXPIRES_AT = '2026-09-14T08:00:00-03:00';

    /** @var array{pfx: string, password: string}|null */
    private static ?array $a1 = null;

    private VaultResolver $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->vault = app(VaultResolver::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_confirm_renews_authors_whose_token_is_within_24h(): void
    {
        $account = $this->createAccount();
        $certificate = $this->certificateFor($account);
        $this->authorFor($account, $certificate, [
            'metadata' => ['token_expires_at' => now()->addHours(2)->toIso8601String()],
        ]);
        $this->contractWithCredential();
        $this->openTransport();

        $transport = new FakeSerproTransport([], [[
            'status' => 200,
            'body' => ['dados' => [
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => self::EXPIRES_AT,
            ]],
        ]]);
        $this->app->instance(SerproTransport::class, $transport);

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['renewed' => 1]))
            ->assertSuccessful();

        $this->assertCount(1, $transport->callCalls);
        $this->assertSame('ENVIOXMLASSINADO81', $transport->callCalls[0]['envelope']['pedidoDados']['idServico']);
        $this->assertSame(self::TOKEN, $this->vault->get(ProcuradorTermService::tokenRef($account->id)));
    }

    public function test_confirm_reverifies_and_resumes_an_eligible_paused_association(): void
    {
        [, $client, , $enrollment] = $this->pausedContext();
        $this->validGrant($client, '00103');
        $this->openTransport();
        $this->grantTransport();

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['reverified' => 1, 'resumed' => 1]))
            ->assertSuccessful();

        $enrollment->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertNull($enrollment->pause_reason);
        $this->assertSame(3, $enrollment->version);
    }

    public function test_an_ended_association_is_never_resumed(): void
    {
        [$account, $client, , $paused] = $this->pausedContext();

        // Same Client, another definition: only the paused-by-outorga one is
        // verified and resumed; the ended row is never a transition candidate.
        $endedDefinition = MonitoringDefinition::factory()->create([
            'id' => 'ended-'.uniqid(),
            'operations' => ['CONSDECLARACAO13'],
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);
        $ended = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $endedDefinition->id,
            'status' => MonitoringEnrollment::STATUS_ENDED,
            'pause_reason' => null,
            'version' => 5,
            'last_change_at' => now(),
        ]);

        $this->validGrant($client, '00103');
        $this->openTransport();
        $this->grantTransport();

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['reverified' => 1, 'resumed' => 1]))
            ->assertSuccessful();

        $ended->refresh();
        $this->assertSame(MonitoringEnrollment::STATUS_ENDED, $ended->status);
        $this->assertSame(5, $ended->version);
        $this->assertNull($ended->pause_reason);

        $this->assertSame(MonitoringEnrollment::STATUS_ACTIVE, $paused->refresh()->status);
    }

    public function test_near_expiry_grants_are_reverified_and_refreshed(): void
    {
        [, $client] = $this->activeContext();
        $this->validGrant($client, '00103', now()->addDays(10));
        $this->openTransport();
        $this->grantTransport();

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['reverified' => 1]))
            ->assertSuccessful();

        $power = PowerOfAttorney::query()->withoutGlobalScope('account')->sole();
        $expected = Carbon::parse('2027-11-27', 'America/Sao_Paulo')->endOfDay();
        $this->assertSame($expected->getTimestamp(), $power->valid_until->getTimestamp());
    }

    public function test_a_recently_verified_client_is_skipped_inside_the_cache_window(): void
    {
        [, $client, , $enrollment] = $this->pausedContext();
        $this->validGrant($client, '00103');
        $this->openTransport();

        app(ProcurationVerifier::class)->markVerified($client, ['00103' => '2027-11-27']);

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['skipped_cached' => 1]))
            ->assertSuccessful();

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $enrollment->refresh()->status);
    }

    public function test_a_failed_definition_keeps_committed_work_counted_and_the_next_client_processed(): void
    {
        // Client 1: the first definition (lowest id) explodes on the wire; the
        // second one commits a resume that must still be reported.
        $account = $this->createAccount();
        $this->authorFor($account, $this->certificateFor($account), [
            'document' => self::AUTHOR_DOCUMENT,
            'metadata' => ['token_expires_at' => now()->addDays(5)->toIso8601String()],
        ]);
        $this->contractWithCredential();

        $client = Client::factory()->for($account, 'account')->create([
            'cnpj' => self::CLIENT_CNPJ,
            'monitoring_enabled' => true,
        ]);
        $this->validGrant($client, '00103');

        $failing = $this->definition('failing-'.uniqid());
        MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $failing->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
            'last_change_at' => now(),
        ]);

        $committing = $this->definition('committing-'.uniqid());
        $paused = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $committing->id,
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
            'version' => 2,
            'last_change_at' => now(),
        ]);

        // Client 2: a healthy paused association that must still be verified.
        [, $otherClient, , $otherPaused] = $this->pausedContext();
        $this->validGrant($otherClient, '00103');

        $this->openTransport();

        $calls = 0;
        $transport = new FakeSerproTransport;
        $transport->onCall = function () use (&$calls): array {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException('wire exploded');
            }

            return ['status' => 200, 'body' => ['dados' => [[
                'dtexpiracao' => '20271127',
                'nrsistemas' => 1,
                'sistemas' => ['Acessar o sistema DCTFWeb'],
            ]]]];
        };
        $this->app->instance(SerproTransport::class, $transport);

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['reverified' => 2, 'resumed' => 2, 'failed' => 1]))
            ->assertSuccessful();

        $this->assertSame(3, $calls);
        $this->assertSame(MonitoringEnrollment::STATUS_ACTIVE, $paused->refresh()->status);
        $this->assertSame(MonitoringEnrollment::STATUS_ACTIVE, $otherPaused->refresh()->status);
    }

    public function test_preview_reports_due_items_without_any_call_or_change(): void
    {
        [, $client, , $enrollment] = $this->pausedContext();
        $this->validGrant($client, '00103');
        $this->openTransport();

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $this->artisan('monitoring:warm-procuracoes')
            ->expectsOutputToContain($this->expected(['dry_run' => true, 'skipped' => 1]))
            ->assertSuccessful();

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $enrollment->refresh()->status);
    }

    public function test_closed_transport_makes_zero_calls_and_reports_gated(): void
    {
        [$account, $client, , $enrollment] = $this->pausedContext();
        $this->validGrant($client, '00103');

        $transport = new FakeSerproTransport;
        $this->app->instance(SerproTransport::class, $transport);

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['gated' => true, 'skipped' => 1]))
            ->assertSuccessful();

        $this->assertSame([], $transport->tokenCalls);
        $this->assertSame([], $transport->callCalls);
        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $enrollment->refresh()->status);
        $this->assertNull($this->vault->get(ProcuradorTermService::tokenRef($account->id)));
    }

    public function test_one_failing_author_does_not_stop_the_next(): void
    {
        $failing = $this->createAccount();
        $failingCertificate = $this->certificateFor($failing);
        $this->authorFor($failing, $failingCertificate, [
            'document' => self::AUTHOR_DOCUMENT,
            'metadata' => [],
        ]);
        // The author stays nominally eligible (future expiry) but the Account
        // certificate vanished from the registry: the renewal fails locally.
        AccountCertificate::query()->withoutGlobalScope('account')->where('account_id', $failing->id)->delete();

        $healthy = $this->createAccount();
        $this->authorFor($healthy, $this->certificateFor($healthy), [
            'document' => '99888777000166',
            'metadata' => [],
        ]);
        $this->contractWithCredential();
        $this->openTransport();

        $transport = new FakeSerproTransport([], [[
            'status' => 200,
            'body' => ['dados' => [
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => self::EXPIRES_AT,
            ]],
        ]]);
        $this->app->instance(SerproTransport::class, $transport);

        $this->artisan('monitoring:warm-procuracoes --confirm')
            ->expectsOutputToContain($this->expected(['renewed' => 1, 'failed' => 1]))
            ->assertSuccessful();

        $this->assertCount(1, $transport->callCalls);
        $this->assertSame(self::TOKEN, $this->vault->get(ProcuradorTermService::tokenRef($healthy->id)));
        $this->assertNull($this->vault->get(ProcuradorTermService::tokenRef($failing->id)));
    }

    public function test_the_renewed_token_never_appears_in_the_output(): void
    {
        $account = $this->createAccount();
        $this->authorFor($account, $this->certificateFor($account), ['metadata' => []]);
        $this->contractWithCredential();
        $this->openTransport();
        $this->app->instance(SerproTransport::class, new FakeSerproTransport([], [[
            'status' => 200,
            'body' => ['dados' => [
                'autenticar_procurador_token' => self::TOKEN,
                'data_hora_expiracao' => self::EXPIRES_AT,
            ]],
        ]]));

        $exit = Artisan::call('monitoring:warm-procuracoes', ['--confirm' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString(self::TOKEN, Artisan::output());
    }

    public function test_the_routine_is_scheduled_daily_at_five_sao_paulo_with_confirm(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events());

        $event = $events->first(
            fn ($event): bool => str_contains((string) $event->command, 'monitoring:warm-procuracoes'),
        );

        $this->assertNotNull($event, 'The warm-up routine must be registered in the scheduler.');
        $this->assertStringContainsString('--confirm', (string) $event->command);
        $this->assertSame('0 5 * * *', $event->expression);
        $this->assertSame('America/Sao_Paulo', $event->timezone);
    }

    /**
     * @param  array<string, int|bool>  $overrides
     */
    private function expected(array $overrides = []): string
    {
        return (string) json_encode(array_replace([
            'dry_run' => false,
            'gated' => false,
            'renewed' => 0,
            'reverified' => 0,
            'resumed' => 0,
            'skipped' => 0,
            'skipped_cached' => 0,
            'failed' => 0,
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    /**
     * Client with a paused-by-outorga association and the author/certificate
     * needed by a live verification.
     *
     * @return array{0: Account, 1: Client, 2: MonitoringDefinition, 3: MonitoringEnrollment}
     */
    private function pausedContext(): array
    {
        return $this->monitoringContext(MonitoringEnrollment::STATUS_PAUSED, 'outorga pendente', 2);
    }

    /**
     * @return array{0: Account, 1: Client, 2: MonitoringDefinition, 3: MonitoringEnrollment}
     */
    private function activeContext(): array
    {
        return $this->monitoringContext(MonitoringEnrollment::STATUS_ACTIVE, null, 1);
    }

    /**
     * Account with an eligible author (token not yet due), a Client and one
     * enrollment in the given status.
     *
     * @return array{0: Account, 1: Client, 2: MonitoringDefinition, 3: MonitoringEnrollment}
     */
    private function monitoringContext(string $status, ?string $pauseReason, int $version): array
    {
        $account = $this->createAccount();
        $this->authorFor($account, $this->certificateFor($account), [
            'document' => self::AUTHOR_DOCUMENT,
            'metadata' => ['token_expires_at' => now()->addDays(5)->toIso8601String()],
        ]);
        $this->contractWithCredential();

        $client = Client::factory()->for($account, 'account')->create([
            'cnpj' => self::CLIENT_CNPJ,
            'monitoring_enabled' => true,
        ]);

        $definition = $this->definition('dctfweb-'.uniqid());

        $enrollment = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'status' => $status,
            'pause_reason' => $pauseReason,
            'version' => $version,
            'last_change_at' => now(),
        ]);

        return [$account, $client, $definition, $enrollment];
    }

    private function definition(string $id): MonitoringDefinition
    {
        return MonitoringDefinition::factory()->create([
            'id' => $id,
            'operations' => ['CONSDECLARACAO13'],
            'requires_procuracao' => true,
            'procuration_codes' => ['00103'],
        ]);
    }

    private function grantTransport(): FakeSerproTransport
    {
        $transport = new FakeSerproTransport([], [[
            'status' => 200,
            'body' => ['dados' => [[
                'dtexpiracao' => '20271127',
                'nrsistemas' => 1,
                'sistemas' => ['Acessar o sistema DCTFWeb'],
            ]]],
        ]]);
        $this->app->instance(SerproTransport::class, $transport);

        return $transport;
    }

    private function validGrant(Client $client, string $code, ?Carbon $validUntil = null): PowerOfAttorney
    {
        return PowerOfAttorney::factory()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'code' => $code,
            'status' => PowerOfAttorney::STATUS_ACTIVE,
            'valid_until' => $validUntil ?? now()->addYear(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function authorFor(
        Account $account,
        AccountCertificate $certificate,
        array $attributes = [],
    ): SerproRequestAuthor {
        $author = SerproRequestAuthor::factory()->for($account, 'account')->create([
            'document_type' => AuthorDocumentType::Pj,
            'name' => 'Autor do Pedido de Dados',
            'status' => AuthorStatus::Active,
            ...$attributes,
        ]);
        $author->useCertificate($certificate);

        return $author->refresh();
    }

    private function certificateFor(Account $account): AccountCertificate
    {
        $a1 = self::a1();
        $ref = 'secret:account-'.$account->id.'-certificate';
        $this->vault->put($ref, [
            'pfx_base64' => base64_encode($a1['pfx']),
            'certificate_password' => $a1['password'],
        ]);

        return AccountCertificate::factory()->for($account, 'account')->create([
            'vault_ref' => $ref,
            'holder_name' => 'Escritório Teste',
            'expires_at' => now()->addYear(),
        ]);
    }

    private function contractWithCredential(): SerproContract
    {
        $ref = 'secret:contratante-'.uniqid();
        $this->vault->put($ref, [
            'client_id' => '179024',
            'consumer_secret' => 'consumer-secret',
            'contratante_doc' => self::CONTRATANTE_DOCUMENT,
        ]);

        return SerproContract::query()->updateOrCreate(
            ['environment' => 'homologacao'],
            ['credential_ref' => $ref],
        );
    }

    private function openTransport(): void
    {
        SerproSettings::current()->update([
            'transport_approved' => true,
            'transport_approved_at' => now(),
        ]);
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
