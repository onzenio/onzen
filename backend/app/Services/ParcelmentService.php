<?php

namespace App\Services;

use App\Integrations\Serpro\SerproFixtureProvider;
use App\Models\Client;
use App\Models\ParcelmentOrder;
use Illuminate\Support\Collection;
use RuntimeException;

class ParcelmentService
{
    /**
     * Oito modalidades do catálogo: seis suportadas, duas fora de escopo
     * (FGTS Digital e PERDCOMP) marcadas como indisponíveis.
     *
     * @return list<array{code: string, name: string, supported: bool}>
     */
    public static function modalities(): array
    {
        return [
            ['code' => '012', 'name' => 'Simples Nacional', 'supported' => true],
            ['code' => '021', 'name' => 'MEI', 'supported' => true],
            ['code' => '022', 'name' => 'IRPF', 'supported' => true],
            ['code' => '023', 'name' => 'DCTFWeb – Débitos', 'supported' => true],
            ['code' => '024', 'name' => 'Transação PGFN', 'supported' => true],
            ['code' => '025', 'name' => 'Demais débitos RFB', 'supported' => true],
            ['code' => 'FGTS', 'name' => 'FGTS Digital', 'supported' => false],
            ['code' => 'PERDCOMP', 'name' => 'PERDCOMP', 'supported' => false],
        ];
    }

    public function __construct(private readonly SerproFixtureProvider $fixtures) {}

    /**
     * @return Collection<int, ParcelmentOrder>
     */
    public function list(Client $client, string $modalityCode): Collection
    {
        $modality = collect(self::modalities())->firstWhere('code', $modalityCode);

        if ($modality === null || ! $modality['supported']) {
            throw new RuntimeException("Modalidade {$modalityCode} indisponível para consulta.");
        }

        $this->syncFromFixture($client, $modalityCode);

        return ParcelmentOrder::query()->withoutGlobalScopes()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->where('modality_code', $modalityCode)
            ->with(['installments', 'payments'])
            ->get();
    }

    private function syncFromFixture(Client $client, string $modalityCode): void
    {
        $fixture = $this->fixtures->load('consultar-parcelamentos');

        foreach ($fixture['payload']['modalidades'] ?? [] as $modality) {
            if (($modality['codigo'] ?? null) !== $modalityCode) {
                continue;
            }

            foreach ($modality['pedidos'] ?? [] as $pedido) {
                $order = ParcelmentOrder::query()->withoutGlobalScopes()->firstOrCreate(
                    [
                        'account_id' => $client->account_id,
                        'client_id' => $client->id,
                        'modality_code' => $modalityCode,
                        'order_number' => $pedido['numero'] ?? 'S/N',
                    ],
                    [
                        'status' => $pedido['situacao'] ?? 'ativo',
                        'total_value' => $pedido['valor_total'] ?? null,
                    ],
                );

                foreach ($pedido['parcelas'] ?? [] as $i => $parcela) {
                    $order->installments()->firstOrCreate(
                        ['parcelment_order_id' => $order->id, 'number' => $parcela['numero'] ?? $i + 1],
                        [
                            'due_date' => $parcela['vencimento'] ?? null,
                            'value' => $parcela['valor'] ?? null,
                            'status' => $parcela['situacao'] ?? 'aberta',
                        ],
                    );
                }

                foreach ($pedido['pagamentos'] ?? [] as $pagamento) {
                    $order->payments()->firstOrCreate(
                        [
                            'parcelment_order_id' => $order->id,
                            'receipt' => $pagamento['recibo'] ?? 'S/R',
                        ],
                        [
                            'paid_at' => $pagamento['data'] ?? null,
                            'value' => $pagamento['valor'] ?? null,
                        ],
                    );
                }
            }
        }
    }
}
