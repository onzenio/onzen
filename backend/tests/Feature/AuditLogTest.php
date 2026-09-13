<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'id', 'actor_user_id', 'origin_account_id', 'target_account_id',
            'action', 'metadata', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));
    }

    public function test_audit_log_persists_with_metadata_cast_and_no_updated_at(): void
    {
        $actor = $this->createUser();
        $origin = $actor->account;
        $target = $this->createAccount();

        $log = AuditLog::factory()->create([
            'actor_user_id' => $actor->id,
            'origin_account_id' => $origin->id,
            'target_account_id' => $target->id,
            'action' => 'account.switch.enter',
            'metadata' => ['ip' => '127.0.0.1'],
        ]);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'account.switch.enter']);
        $this->assertSame(['ip' => '127.0.0.1'], $log->refresh()->metadata);
        $this->assertNull($log->getAttribute('updated_at'));
        $this->assertTrue($log->actor->is($actor));
    }

    public function test_audit_log_allows_null_actor_for_system_events(): void
    {
        $origin = $this->createAccount();

        $log = AuditLog::factory()->create([
            'actor_user_id' => null,
            'origin_account_id' => $origin->id,
        ]);

        $this->assertNull($log->refresh()->actor_user_id);
        $this->assertNull($log->actor);
    }
}
