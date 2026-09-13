<?php

namespace Tests\Feature;

use App\Contracts\ArtifactStore;
use App\Integrations\Serpro\ConsultArtifactStore;
use App\Models\Client;
use App\Models\MonitoringArtifact;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConsultArtifactStoreTest extends TestCase
{
    use RefreshDatabase;

    private function consultStore(): ConsultArtifactStore
    {
        return app(ConsultArtifactStore::class);
    }

    public function test_base64_fields_are_decoded_stored_and_replaced_by_opaque_refs(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);
        $client = Client::factory()->for($account, 'account')->create();
        $decoded = '%PDF-1.4 consult-canary-payload';

        $result = $this->consultStore()->persist([
            'dados' => [
                'periodoApuracao' => '202601',
                'nomeArquivoRecibo' => 'recibo.pdf',
                'reciboPdf' => base64_encode($decoded),
            ],
        ], 'CONSDECREC15', ['client_id' => $client->id]);

        $sanitized = $result['payload'];
        $this->assertNull($sanitized['dados']['reciboPdf']);
        $ref = $sanitized['dados']['reciboPdf_storage_ref'];
        $this->assertSame(hash('sha256', $decoded), $sanitized['dados']['reciboPdf_hash_sha256']);
        $this->assertSame($decoded, app(ArtifactStore::class)->get($ref));

        $this->assertCount(1, $result['artifacts']);
        $this->assertSame($ref, $result['artifacts'][0]['ref']);
        $this->assertSame('recibo.pdf', $result['artifacts'][0]['filename']);
        $this->assertSame('reciboPdf', $result['artifacts'][0]['field']);
        $this->assertSame('pdf', $result['artifacts'][0]['kind']);
        $this->assertSame([], $result['failures']);

        $row = MonitoringArtifact::query()->where('ref', $ref)->sole();
        $this->assertSame($account->id, $row->account_id);
        $this->assertSame($client->id, $row->client_id);
        $this->assertSame('CONSDECREC15', $row->source);
        $this->assertSame('recibo.pdf', $row->original_name);
        $this->assertSame($decoded, app(ArtifactStore::class)->get($ref));

        $encoded = (string) json_encode($result['payload']);
        $this->assertStringNotContainsString('consult-canary-payload', $encoded);
        $this->assertStringNotContainsString(base64_encode($decoded), $encoded);
    }

    public function test_xml_fields_use_the_official_nome_arquivo_name(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);
        $decoded = '<declaracao>canary</declaracao>';

        $result = $this->consultStore()->persist([
            'dados' => [
                'nomeArquivoXml' => 'declaracao-202601.xml',
                'declaracaoXml' => base64_encode($decoded),
            ],
        ], 'CONSXMLDECLARACAO38');

        $this->assertSame('declaracao-202601.xml', $result['artifacts'][0]['filename']);
        $this->assertSame('xml', $result['artifacts'][0]['kind']);
        $this->assertSame($decoded, app(ArtifactStore::class)->get($result['artifacts'][0]['ref']));
    }

    public function test_payloads_are_walked_recursively_and_scalar_values_are_kept(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);
        $decoded = '%PDF-1.4 nested-report';

        $result = $this->consultStore()->persist([
            'dados' => [
                'status' => 'Sucesso',
                'itens' => [
                    ['nomeArquivoRelatorio' => 'relatorio.pdf', 'relatorioPdf' => base64_encode($decoded)],
                ],
            ],
        ], 'RELATORIOSITFIS92');

        $sanitized = $result['payload'];
        $this->assertSame('Sucesso', $sanitized['dados']['status']);
        $this->assertNull($sanitized['dados']['itens'][0]['relatorioPdf']);
        $this->assertArrayHasKey('relatorioPdf_storage_ref', $sanitized['dados']['itens'][0]);
        $this->assertCount(1, $result['artifacts']);
        $this->assertSame($decoded, app(ArtifactStore::class)->get($result['artifacts'][0]['ref']));
    }

    public function test_decode_failure_records_the_artifact_failure_and_does_not_break_the_execution(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);
        $good = base64_encode('%PDF-1.4 declaracao-ok');

        $result = $this->consultStore()->persist([
            'dados' => [
                'nomeArquivoRecibo' => 'recibo.pdf',
                'reciboPdf' => 'AAAAAAAAA',
                'nomeArquivoDeclaracao' => 'declaracao.pdf',
                'declaracaoPdf' => $good,
            ],
        ], 'CONSDECREC15');

        $sanitized = $result['payload'];
        $this->assertNull($sanitized['dados']['reciboPdf']);
        $this->assertSame('decode_failed', $sanitized['dados']['reciboPdf_artifact_error']);
        $this->assertArrayNotHasKey('reciboPdf_storage_ref', $sanitized['dados']);

        $this->assertCount(1, $result['failures']);
        $this->assertSame('reciboPdf', $result['failures'][0]['field']);
        $this->assertSame('decode_failed', $result['failures'][0]['reason']);

        $this->assertCount(1, $result['artifacts']);
        $this->assertSame('declaracao.pdf', $result['artifacts'][0]['filename']);

        $this->assertDatabaseCount('monitoring_artifacts', 1);
        $this->assertDatabaseMissing('monitoring_artifacts', ['original_name' => 'recibo.pdf']);
    }

    public function test_binary_named_fields_without_base64_content_are_left_untouched(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);

        $result = $this->consultStore()->persist([
            'dados' => ['relatorioPdf' => 'Relatório indisponível'],
        ], 'RELATORIOSITFIS92');

        $this->assertSame('Relatório indisponível', $result['payload']['dados']['relatorioPdf']);
        $this->assertSame([], $result['artifacts']);
        $this->assertSame([], $result['failures']);
        $this->assertDatabaseCount('monitoring_artifacts', 0);
    }

    public function test_base64_suffixed_fields_are_also_decoded(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);
        $decoded = 'canary-base64-field';

        $result = $this->consultStore()->persist([
            'dados' => ['documentoBase64' => base64_encode($decoded)],
        ], 'CONSDECREC15');

        $this->assertNull($result['payload']['dados']['documentoBase64']);
        $ref = $result['payload']['dados']['documentoBase64_storage_ref'];
        $this->assertSame($decoded, app(ArtifactStore::class)->get($ref));
        $this->assertSame('documentoBase64.bin', $result['artifacts'][0]['filename']);
        $this->assertSame('other', $result['artifacts'][0]['kind']);
    }

    public function test_gerardas_bare_pdf_gets_the_pgdasd_official_name(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);

        $result = $this->consultStore()->persist([
            'dados' => [
                'periodoApuracao' => '202601',
                'pdf' => base64_encode('%PDF-1.4 das'),
            ],
        ], 'GERARDAS12');

        $this->assertSame('PGDASD-DAS-202601.pdf', $result['artifacts'][0]['filename']);
    }

    public function test_artifacts_are_linked_to_the_enrollment_context(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);

        $result = $this->consultStore()->persist([
            'dados' => ['nomeArquivoExtrato' => 'extrato.pdf', 'extratoPdf' => base64_encode('%PDF-1.4 extrato')],
        ], 'CONSEXTRATO16', ['enrollment_id' => 42]);

        $row = MonitoringArtifact::query()->where('ref', $result['artifacts'][0]['ref'])->sole();
        $this->assertSame(42, $row->enrollment_id);
    }

    public function test_decode_failure_events_never_contain_fiscal_content(): void
    {
        Storage::fake('local');
        $account = $this->createAccount();
        CurrentAccount::set($account->id);

        Log::spy();

        $this->consultStore()->persist([
            'dados' => ['reciboPdf' => 'AAAAAAAAA'],
        ], 'CONSDECREC15');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                $encoded = (string) json_encode([$message, $context]);

                return ! str_contains($encoded, 'AAAAAAAAA');
            });
    }
}
