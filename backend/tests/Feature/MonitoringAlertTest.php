<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Services\Monitoring\MonitoringAlertService;
use App\Support\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonitoringAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_alerts', [
            'id', 'account_id', 'enrollment_id', 'client_id', 'change_id',
            'status', 'acknowledged_by_user_id', 'acknowledged_at', 'created_at',
        ]));
    }

    public function test_a_pending_alert_exposes_its_relations(): void
    {
        [$alert, $enrollment, $client, $change] = $this->context();

        $this->assertSame(MonitoringAlert::STATUS_PENDING, $alert->status);
        $this->assertFalse($alert->isAcknowledged());
        $this->assertTrue($alert->enrollment->is($enrollment));
        $this->assertTrue($alert->client->is($client));
        $this->assertTrue($alert->change->is($change));
        $this->assertNull($alert->acknowledgedBy);
    }

    public function test_an_admin_acknowledges_idempotently_with_a_single_audit_entry(): void
    {
        [$alert, $enrollment, $client, $change] = $this->context();
        $admin = $this->createUser($alert->account, ['role' => UserRole::Admin]);
        $service = app(MonitoringAlertService::class);

        $first = $service->acknowledge($alert, $admin);

        $this->assertTrue($first->isAcknowledged());
        $this->assertSame($admin->id, $first->acknowledged_by_user_id);
        $this->assertNotNull($first->acknowledged_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.alert.acknowledged')->count());

        $second = $service->acknowledge($first, $admin);

        $this->assertSame($first->acknowledged_at->toIso8601String(), $second->acknowledged_at->toIso8601String());
        $this->assertSame($admin->id, $second->acknowledged_by_user_id);
        $this->assertSame(1, MonitoringAlert::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.alert.acknowledged')->count());

        $audit = AuditLog::query()->where('action', 'monitoring.alert.acknowledged')->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame($alert->account_id, $audit->origin_account_id);
        $this->assertSame($alert->id, $audit->metadata['alert_id']);
        $this->assertSame($enrollment->id, $audit->metadata['enrollment_id']);
        $this->assertSame($client->id, $audit->metadata['client_id']);
        $this->assertSame($change->id, $audit->metadata['change_id']);
    }

    public function test_an_operator_can_acknowledge(): void
    {
        [$alert] = $this->context();
        $operator = $this->createUser($alert->account, ['role' => UserRole::Operator]);

        $acknowledged = app(MonitoringAlertService::class)->acknowledge($alert, $operator);

        $this->assertTrue($acknowledged->isAcknowledged());
        $this->assertSame($operator->id, $acknowledged->acknowledged_by_user_id);
    }

    public function test_a_regular_user_cannot_acknowledge(): void
    {
        [$alert] = $this->context();
        $user = $this->createUser($alert->account, ['role' => UserRole::User]);

        try {
            app(MonitoringAlertService::class)->acknowledge($alert, $user);
            $this->fail('A regular user was able to acknowledge an alert.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(MonitoringAlert::STATUS_PENDING, $alert->fresh()->status);
        $this->assertNull($alert->fresh()->acknowledged_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'monitoring.alert.acknowledged')->count());
    }

    public function test_an_admin_of_another_account_cannot_acknowledge(): void
    {
        [$alert] = $this->context();
        $foreign = $this->createUser($this->createAccount(), ['role' => UserRole::Admin]);

        // Como numa request do operador estrangeiro: a Account efetiva é a dele.
        CurrentAccount::set($foreign->account_id);

        $this->expectException(AuthorizationException::class);

        app(MonitoringAlertService::class)->acknowledge($alert, $foreign);
    }

    public function test_the_acknowledge_policy_matches_the_write_roles(): void
    {
        [$alert] = $this->context();
        $account = $alert->account;
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $user = $this->createUser($account, ['role' => UserRole::User]);
        $foreign = $this->createUser($this->createAccount(), ['role' => UserRole::Admin]);

        CurrentAccount::set($account->id);

        $this->assertTrue(Gate::forUser($admin)->allows('acknowledge', $alert));
        $this->assertTrue(Gate::forUser($operator)->allows('acknowledge', $alert));
        $this->assertFalse(Gate::forUser($user)->allows('acknowledge', $alert));

        // Cross-account: with the effective Account set to the foreign one,
        // neither the foreign admin (alert is not in their Account) nor the
        // alert admin (actor is not in the effective Account) are allowed.
        CurrentAccount::set($foreign->account_id);
        $this->assertFalse(Gate::forUser($foreign)->allows('acknowledge', $alert));
        $this->assertFalse(Gate::forUser($admin)->allows('acknowledge', $alert));
    }

    public function test_the_account_scope_filters_alerts(): void
    {
        [$alert] = $this->context();
        [$foreignAlert] = $this->context();

        CurrentAccount::set($alert->account_id);

        $visible = MonitoringAlert::query()->get();

        $this->assertCount(1, $visible);
        $this->assertTrue($visible->first()->is($alert));
        $this->assertFalse($visible->first()->is($foreignAlert));
    }

    /**
     * @return array{0: MonitoringAlert, 1: MonitoringEnrollment, 2: Client, 3: MonitoringChange}
     */
    private function context(): array
    {
        $account = $this->createAccount();
        // Leituras diretas de relations honram o escopo fail-closed: fixa o
        // contexto como faria o ResolveAccount numa request.
        CurrentAccount::set($account->id);
        $client = Client::factory()->for($account, 'account')->create();
        $definition = MonitoringDefinition::factory()->create();
        $enrollment = MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ]);
        $run = MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'operation_code' => 'RELATORIOSITFIS92',
        ]);
        $snapshot = MonitoringSnapshot::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $client->id,
            'run_id' => $run->id,
        ]);
        $change = MonitoringChange::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $client->id,
            'snapshot_id' => $snapshot->id,
        ]);
        $alert = MonitoringAlert::factory()->create([
            'account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'client_id' => $client->id,
            'change_id' => $change->id,
        ]);

        return [$alert, $enrollment, $client, $change];
    }
}
