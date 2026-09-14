<?php

namespace App\Services\Monitoring;

use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\FamilyConsultNormalizer;
use App\Integrations\Serpro\ParcelmentNormalizer;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use Illuminate\Support\Facades\DB;

/**
 * Projeta o resultado de uma consulta de parcelamento nas tabelas
 * normalizadas (Task 26 / design decisão 9).
 *
 * Integração: chamado pelo {@see SerproExecutor} na mesma transação da
 * conclusão, logo após o `ResultProjector` (Snapshot/Changes/Alerts), do
 * mesmo jeito que a cadeia PGDAS-D — só um resultado completo chega aqui, e
 * uma falha de projeção derruba a conclusão inteira (fail-closed) em vez de
 * gravar parcelamento órfão. 429/transiente nunca chegam: o executor só
 * projeta `completed`.
 *
 * Cada operação mapeia para a modalidade via `ConsultCatalog`: PEDIDOSPARC* e
 * PARCELASPARAGERAR* trazem a lista/rol de pedidos, OBTERPARC* traz o detalhe
 * com parcelas e DETPAGTOPARC* traz o detalhe de pagamento de uma parcela
 * (pedido + parcela + pagamento). Reprojetar é idempotente pelas chaves
 * naturais do normalizador.
 */
final class ParcelmentConsultProjector
{
    public function __construct(
        private readonly FamilyConsultNormalizer $normalizer,
        private readonly ParcelmentNormalizer $parcelments,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public function project(MonitoringRun $run, array $result): void
    {
        $operation = strtoupper(trim((string) ($result['operation_code'] ?? $run->operation_code ?? '')));

        if (! ConsultCatalog::isParcelmentConsult($operation)) {
            return;
        }

        $modality = ConsultCatalog::parcelmentModality($operation);

        if ($modality === null || ! in_array($modality, ParcelmentNormalizer::MODALITIES, true)) {
            return;
        }

        // Fail-closed: sem a Associação não há Client a vincular; nada é
        // projetado com Account adivinhada.
        $enrollment = MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->whereKey($run->enrollment_id)
            ->first();

        if ($enrollment === null) {
            return;
        }

        $envelope = $this->normalizer->normalize(
            $operation,
            is_array($result['body'] ?? null) ? $result['body'] : [],
            (string) ($result['source'] ?? 'serpro'),
        );

        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $provenance = (string) ($envelope['provenance'] ?? 'serpro');

        if ($data === []) {
            return;
        }

        DB::transaction(function () use ($run, $operation, $modality, $enrollment, $data, $provenance): void {
            if (str_starts_with($operation, 'DETPAGTOPARC')) {
                $this->projectPaymentDetail($run, $operation, $modality, $enrollment, $data, $provenance);

                return;
            }

            $this->projectOrders($run, $operation, $modality, $enrollment, $data, $provenance);
        });
    }

    /**
     * Operações de lista (PEDIDOSPARC, PARCELASPARAGERAR e OBTERPARC): uma
     * lista `pedidos` ou um pedido único no topo do `dados`, cada um com suas
     * `parcelas`.
     *
     * @param  array<string, mixed>  $data
     */
    private function projectOrders(
        MonitoringRun $run,
        string $operation,
        string $modality,
        MonitoringEnrollment $enrollment,
        array $data,
        string $provenance,
    ): void {
        $orders = is_array($data['pedidos'] ?? null) ? $data['pedidos'] : [];

        if ($orders === [] && (isset($data['id']) || isset($data['numero']))) {
            $orders = [$data];
        }

        $position = 0;

        foreach ($orders as $orderData) {
            if (! is_array($orderData)) {
                continue;
            }

            $position++;

            $order = $this->parcelments->normalizeOrder(
                [
                    'external_id' => $orderData['external_id']
                        ?? $orderData['id']
                        ?? $orderData['numero']
                        ?? $operation.'-'.$position,
                    ...$orderData,
                    'operation_code' => $operation,
                ],
                (int) $run->account_id,
                (int) $enrollment->client_id,
                (int) $enrollment->getKey(),
                $modality,
                $provenance,
            );

            $installments = is_array($orderData['parcelas'] ?? null)
                ? $orderData['parcelas']
                : (is_array($data['parcelas'] ?? null) ? $data['parcelas'] : []);

            $number = 0;

            foreach ($installments as $installmentData) {
                if (! is_array($installmentData)) {
                    continue;
                }

                $number++;

                $installment = $this->parcelments->normalizeInstallment(
                    $order,
                    $installmentData + ['numeroParcela' => $number],
                    $provenance,
                );

                $paymentData = $this->paymentData($data, $installmentData);

                if ($paymentData !== null) {
                    $this->parcelments->normalizePayment($installment, $paymentData, $provenance);
                }
            }
        }
    }

    /**
     * DETPAGTOPARC*: o próprio `dados` é o detalhe de pagamento de uma
     * parcela; porta o comportamento do `_legacy` (pedido + parcela +
     * pagamento a partir do mesmo payload).
     *
     * @param  array<string, mixed>  $data
     */
    private function projectPaymentDetail(
        MonitoringRun $run,
        string $operation,
        string $modality,
        MonitoringEnrollment $enrollment,
        array $data,
        string $provenance,
    ): void {
        $order = $this->parcelments->normalizeOrder(
            [
                'external_id' => $data['external_id'] ?? $data['id'] ?? $data['numero'] ?? $operation.'-1',
                ...$data,
                'operation_code' => $operation,
            ],
            (int) $run->account_id,
            (int) $enrollment->client_id,
            (int) $enrollment->getKey(),
            $modality,
            $provenance,
        );

        $installment = $this->parcelments->normalizeInstallment(
            $order,
            $data + ['numeroParcela' => $data['numeroParcela'] ?? $data['numero_parcela'] ?? 1],
            $provenance,
        );

        $this->parcelments->normalizePayment($installment, $data, $provenance);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasPayment(array $data): bool
    {
        return isset($data['numeroDas'])
            || isset($data['numero_das'])
            || isset($data['dataPagamento'])
            || isset($data['data_pagamento'])
            || isset($data['dataArrecadacao']);
    }

    /**
     * O payload que carrega os fatos do pagamento: quando o sinal está na
     * própria parcela, a linha da parcela tem precedência sobre o `dados` do
     * pedido (merge com a parcela por cima); quando só o pedido carrega o
     * sinal, o comportamento legado é mantido (pagamento lido do pedido).
     * `null` quando nenhum dos dois traz sinal — nenhum pagamento é inventado.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $installmentData
     * @return array<string, mixed>|null
     */
    private function paymentData(array $data, array $installmentData): ?array
    {
        if (! $this->hasPayment($installmentData)) {
            return $this->hasPayment($data) ? $data : null;
        }

        return array_merge($data, $installmentData);
    }
}
