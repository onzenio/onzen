<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringSnapshot;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeticao_sem_mudanca_nao_versiona_nem_alerta(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create();
        $service = app(SnapshotService::class);

        $normalized = ['normalized' => true, 'family' => 'sitfis', 'situacao_fiscal' => 'regular'];

        $first = $service->record($account, $client, 'sitfis', $normalized);
        $second = $service->record($account, $client, 'sitfis', $normalized);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $first->version);
        $this->assertSame(1, MonitoringSnapshot::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, MonitoringChange::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, MonitoringAlert::query()->withoutGlobalScopes()->count());
    }

    public function test_mudanca_gera_snapshot_mudanca_e_alerta_unico(): void
    {
        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create();
        $service = app(SnapshotService::class);

        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'regular']);
        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'irregular']);

        $this->assertSame(2, MonitoringSnapshot::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, MonitoringSnapshot::query()->withoutGlobalScopes()->orderByDesc('version')->firstOrFail()->version);
        $this->assertSame(1, MonitoringChange::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, MonitoringAlert::query()->withoutGlobalScopes()->where('status', 'open')->count());
    }

    public function test_acknowledge_idempotente_e_auditado(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Operator]);
        $client = Client::factory()->for($account, 'account')->create();
        $service = app(SnapshotService::class);

        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'regular']);
        $service->record($account, $client, 'sitfis', ['situacao_fiscal' => 'irregular']);

        $alert = MonitoringAlert::query()->withoutGlobalScopes()->firstOrFail();

        $service->acknowledge($alert, $actor);
        $this->assertTrue($alert->refresh()->isAcknowledged());

        // Reconhecimento repetido: bem-sucedido, sem duplicar registro.
        $service->acknowledge($alert->refresh(), $actor);
        $this->assertSame(1, \App\Models\AuditLog::query()->where('action', 'monitoring_alert.acknowledged')->count());
    }
}
