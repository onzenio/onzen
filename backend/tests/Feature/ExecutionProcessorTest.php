<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MonitoringArtifact;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Services\ExecutionProcessor;
use Database\Seeders\MonitoringDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExecutionProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_artefato_gerado_no_processamento(): void
    {
        Storage::fake('local');
        $this->seed(MonitoringDefinitionSeeder::class);

        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create();
        $run = MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_code' => 'dctfweb',
            'status' => MonitoringRun::RUNNING,
        ]);

        $processed = app(ExecutionProcessor::class)->processCompletedRun($run, [
            'competencia' => '2025-01',
            'conteudos' => [
                ['nome' => 'recibo.pdf', 'mime' => 'application/pdf', 'base64' => base64_encode('%PDF-RECIBO')],
            ],
        ]);

        $this->assertSame(MonitoringRun::COMPLETED, $processed->status);
        $this->assertNotNull($processed->artifact_ref);
        $this->assertSame(1, MonitoringSnapshot::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, MonitoringArtifact::query()->withoutGlobalScopes()->count());
    }

    public function test_falha_de_artefato_nao_quebra_execucao(): void
    {
        Storage::fake('local');
        $this->seed(MonitoringDefinitionSeeder::class);

        $account = $this->createAccount();
        $client = Client::factory()->for($account, 'account')->create();
        $run = MonitoringRun::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_code' => 'dctfweb',
            'status' => MonitoringRun::RUNNING,
        ]);

        $processed = app(ExecutionProcessor::class)->processCompletedRun($run, [
            'competencia' => '2025-01',
            'conteudos' => [['nome' => 'x.pdf', 'base64' => '!!!nao-base64!!!']],
        ]);

        $this->assertSame(MonitoringRun::COMPLETED, $processed->status);
        $this->assertNotNull($processed->artifact_error);
        $this->assertNull($processed->artifact_ref);
        $this->assertSame(1, MonitoringSnapshot::query()->withoutGlobalScopes()->count());
    }
}
