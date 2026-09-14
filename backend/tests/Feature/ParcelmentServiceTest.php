<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\ParcelmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParcelmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_modalidades_oito_com_duas_indisponiveis(): void
    {
        $modalities = ParcelmentService::modalities();

        $this->assertCount(8, $modalities);
        $this->assertCount(6, array_filter($modalities, fn ($m) => $m['supported']));
    }

    public function test_consulta_normalizada_modalidade_suportada(): void
    {
        $client = Client::factory()->create();

        $orders = app(ParcelmentService::class)->list($client, '012');

        $this->assertCount(1, $orders);
        $order = $orders->first();
        $this->assertSame('PED-SINTETICO-1', $order->order_number);
        $this->assertCount(2, $order->installments);
        $this->assertCount(1, $order->payments);
        $this->assertSame('REC-SINTETICO-PARC', $order->payments->first()->receipt);
    }

    public function test_modalidade_indisponivel_falha_explicita(): void
    {
        $client = Client::factory()->create();

        foreach (['FGTS', 'PERDCOMP', 'XXX'] as $code) {
            try {
                app(ParcelmentService::class)->list($client, $code);
                $this->fail("{$code} deveria falhar de forma explícita");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($code, $e->getMessage());
            }
        }
    }

    public function test_isolamento_por_account(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();

        app(ParcelmentService::class)->list($a, '012');

        $this->assertCount(1, app(ParcelmentService::class)->list($a, '012'));
        $this->assertSame(0, \App\Models\ParcelmentOrder::query()->withoutGlobalScopes()
            ->where('account_id', $b->account_id)->count());

        // Repetição não duplica (idempotência do sync).
        app(ParcelmentService::class)->list($a, '012');
        $this->assertSame(1, \App\Models\ParcelmentOrder::query()->withoutGlobalScopes()->count());
    }
}
