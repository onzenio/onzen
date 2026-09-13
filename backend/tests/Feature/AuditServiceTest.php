<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AuditService::class);
    }

    public function test_record_persists_audit_log_with_actor_action_and_metadata(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);

        $log = $this->service->record($actor, 'serpro.credentials_replaced', [
            'masked_identifier' => 'secret:****1234',
        ]);

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'actor_user_id' => $actor->id,
            'origin_account_id' => $account->id,
            'action' => 'serpro.credentials_replaced',
        ]);
        $this->assertSame(['masked_identifier' => 'secret:****1234'], $log->refresh()->metadata);
        $this->assertNotNull($log->created_at);
    }

    public function test_record_falls_back_to_current_account_when_actor_has_none(): void
    {
        $account = $this->createAccount();
        $actor = User::factory()->create(['account_id' => null]);
        CurrentAccount::set($account->id);

        $log = $this->service->record($actor, 'monitoring.run_queued');

        $this->assertSame($account->id, $log->origin_account_id);
    }

    public function test_record_uses_platform_account_a_for_platform_events(): void
    {
        $platform = $this->createAccount(['profile' => AccountProfile::A]);

        $log = $this->service->record(null, 'serpro.environment_changed');

        $this->assertSame($platform->id, $log->origin_account_id);
        $this->assertNull($log->actor_user_id);
        $this->assertNotNull($log->created_at);
    }

    public function test_record_redacts_secret_like_metadata_recursively(): void
    {
        $actor = $this->createUser();

        $log = $this->service->record($actor, 'serpro.credentials_replaced', [
            'password' => 'super-secret',
            'consumer_secret' => 'consumer-value',
            'pfx_base64' => 'UEZYYnl0ZXM=',
            'procurador_token' => 'token-value',
            'masked_identifier' => 'secret:****1234',
            'nested' => ['certificate_password' => 'p@ss', 'keep' => 'ok'],
        ]);

        $metadata = $log->refresh()->metadata;

        $this->assertSame('[redacted]', $metadata['password']);
        $this->assertSame('[redacted]', $metadata['consumer_secret']);
        $this->assertSame('[redacted]', $metadata['pfx_base64']);
        $this->assertSame('[redacted]', $metadata['procurador_token']);
        $this->assertSame('[redacted]', $metadata['nested']['certificate_password']);
        $this->assertSame('ok', $metadata['nested']['keep']);
        $this->assertSame('secret:****1234', $metadata['masked_identifier']);

        $encoded = (string) json_encode($metadata);
        $this->assertStringNotContainsString('super-secret', $encoded);
        $this->assertStringNotContainsString('consumer-value', $encoded);
        $this->assertStringNotContainsString('p@ss', $encoded);
    }

    public function test_record_is_best_effort_and_logs_when_persistence_fails(): void
    {
        $actor = $this->createUser();
        Log::shouldReceive('error')->once();
        AuditLog::creating(fn () => throw new RuntimeException('audit store unavailable'));

        $log = $this->service->record($actor, 'serpro.credentials_replaced');

        $this->assertNull($log);
    }

    public function test_record_is_best_effort_when_no_origin_account_resolves(): void
    {
        Log::shouldReceive('error')->once();

        $log = $this->service->record(null, 'platform.event');

        $this->assertNull($log);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_insert_uses_a_savepoint_inside_an_outer_transaction(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        $observedLevel = null;
        $outerLevel = null;

        AuditLog::creating(function () use (&$observedLevel): void {
            $observedLevel = DB::transactionLevel();
        });

        DB::transaction(function () use ($actor, &$outerLevel): void {
            $outerLevel = DB::transactionLevel();
            $this->service->record($actor, 'serpro.credentials_replaced');
        });

        $this->assertSame($outerLevel + 1, $observedLevel);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_audit_failure_inside_an_outer_transaction_does_not_abort_it(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        Log::shouldReceive('error')->once();

        $client = DB::transaction(function () use ($actor, $account): Client {
            $ghost = new Account;
            $ghost->id = 999_999_999;

            $log = $this->service->record($actor, 'serpro.credentials_replaced', [], $ghost);
            $this->assertNull($log);

            return Client::factory()->create([
                'account_id' => $account->id,
                'cnpj' => '12345678000195',
            ]);
        });

        $this->assertTrue($client->exists);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }
}
