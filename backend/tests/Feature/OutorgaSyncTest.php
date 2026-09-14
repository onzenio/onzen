<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Models\Plan;
use App\Models\PowerOfAttorney;
use App\Services\EnrollmentService;
use App\Services\OutorgaSyncService;
use App\Services\ProcurationChecker;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OutorgaSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeCase(?bool $granted): array
    {
        $this->seed(MonitoringDefinitionSeeder::class);

        $account = $this->createAccount();
        $account->forceFill(['plan_id' => Plan::factory()->create(['max_clients' => 10])->id])->save();
        $account = $account->refresh();

        if ($granted !== null) {
            $checker = new class($granted) implements ProcurationChecker
            {
                public function __construct(private readonly bool $granted) {}

                public function granted(Client $client, string $serviceCode): bool
                {
                    return $this->granted;
                }
            };
            $this->app->instance(ProcurationChecker::class, $checker);
        }

        $client = Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => true, 'regime' => 'simples',
        ]);
        $enrollment = app(EnrollmentService::class)->create($account, $client->id, 'sitfis');

        return [$account, $client, $enrollment];
    }

    public function test_pausa_com_motivo_quando_sem_outorga(): void
    {
        [$account, $client, $enrollment] = $this->makeCase(false);

        app(OutorgaSyncService::class)->syncClient($client);

        $enrollment = $enrollment->refresh();
        $this->assertSame(MonitoringEnrollment::PAUSED, $enrollment->status);
        $this->assertSame('outorga pendente', $enrollment->pause_reason);
    }

    public function test_retomada_quando_outorga_verificada(): void
    {
        [$account, $client, $enrollment] = $this->makeCase(false);
        $service = app(OutorgaSyncService::class);
        $service->syncClient($client);
        $this->assertSame(MonitoringEnrollment::PAUSED, $enrollment->refresh()->status);

        // Nova verificação positiva + cache limpo retoma a associação.
        $checker = new class implements ProcurationChecker
        {
            public function granted(Client $client, string $serviceCode): bool
            {
                return true;
            }
        };
        $this->app->instance(ProcurationChecker::class, $checker);
        Cache::flush();

        app(OutorgaSyncService::class)->syncClient($client);

        $this->assertSame(MonitoringEnrollment::ACTIVE, $enrollment->refresh()->status);
    }

    public function test_divergencias_isoladas_por_account_e_404_cross_account(): void
    {
        [$account, $client, $enrollment] = $this->makeCase(false);
        app(OutorgaSyncService::class)->syncClient($client);

        $user = $this->createUser($account, ['role' => UserRole::Operator]);

        $response = $this->actingAs($user)->getJson('/api/monitoring/divergences')->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('SITFIS-CONS', $data[0]['service_code']);
        $this->assertSame(PowerOfAttorney::MISSING, $data[0]['status']);

        $other = $this->createAccount();
        $stranger = $this->createUser($other, ['role' => UserRole::Operator]);

        $this->actingAs($stranger)->getJson("/api/monitoring/enrollments/{$enrollment->id}")->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/monitoring/divergences')->assertOk()->assertJsonPath('data', []);
    }
}
