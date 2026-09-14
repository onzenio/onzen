<?php

namespace App\Integrations\Serpro;

use App\Models\ParcelmentInstallment;
use App\Models\ParcelmentOrder;
use App\Models\ParcelmentPayment;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Normalizador de parcelamentos (porte do `_legacy` adaptado ao OneFisc).
 *
 * Converte o `dados` normalizado de PEDIDOSPARC, OBTERPARC,
 * PARCELASPARAGERAR e DETPAGTOPARC em pedidos, parcelas e pagamentos
 * persistidos. Cada linha carrega `account_id` e `client_id` (vínculo com o
 * Client por linha, exigido pela spec) e a convergência é pelo par natural de
 * cada tabela — reprojetar a mesma operação atualiza as linhas existentes em
 * vez de duplicar.
 *
 * Campos ausentes viram `null` (fallback neutro); nada é inventado. O
 * `external_id` de cada linha tem fallback determinístico para nunca deixar
 * a chave única vazia. `guide_ref` nunca é tocado aqui: a guia existente
 * sobrevive a qualquer reprojeção.
 *
 * Desvios registrados do legado: `client_id` em parcelas/pagamentos
 * (spec), `paid_at` da parcela lido de `dataPagamento`/`data_pagamento`
 * quando o detalhe de pagamento traz a data, e `provenance` factual
 * (`fixture`/`serpro`) em vez do literal `serpro`.
 */
final class ParcelmentNormalizer
{
    /**
     * @var list<string>
     */
    public const MODALITIES = ConsultCatalog::PARCELMENT_MODALITIES;

    /**
     * @param  array<string, mixed>  $data
     */
    public function normalizeOrder(
        array $data,
        int $accountId,
        int $clientId,
        ?int $enrollmentId,
        string $modality,
        string $provenance = 'serpro',
    ): ParcelmentOrder {
        if (! in_array($modality, self::MODALITIES, true)) {
            throw new InvalidArgumentException('Modalidade de parcelamento indisponível.');
        }

        $externalId = $this->string($data, 'external_id', 'id', 'numero');

        if ($externalId === null) {
            throw new InvalidArgumentException('Pedido de parcelamento sem identificador externo.');
        }

        return ParcelmentOrder::query()
            ->withoutGlobalScope('account')
            ->updateOrCreate(
                [
                    'account_id' => $accountId,
                    'client_id' => $clientId,
                    'modality' => $modality,
                    'external_id' => $externalId,
                ],
                [
                    'enrollment_id' => $enrollmentId,
                    'status' => $this->string($data, 'status', 'situacao'),
                    'installments_count' => $this->int($data, 'installments_count', 'quantidadeParcelas', 'quantidade_parcelas'),
                    'total_amount' => $this->decimal($data, 'total_amount', 'valorTotal', 'valor_total'),
                    'competence' => $this->date($data, 'competence', 'competencia'),
                    'operation_code' => $this->string($data, 'operation_code', 'operacao'),
                    'provenance' => $provenance,
                    'metadata' => $data,
                ],
            );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function normalizeInstallment(
        ParcelmentOrder $order,
        array $data,
        string $provenance = 'serpro',
    ): ParcelmentInstallment {
        $number = $this->int($data, 'number', 'numeroParcela', 'numero_parcela') ?? 0;
        $externalId = $this->string($data, 'external_id', 'id', 'numero')
            ?? $order->external_id.'-'.$number;

        return ParcelmentInstallment::query()
            ->withoutGlobalScope('account')
            ->updateOrCreate(
                [
                    'account_id' => $order->account_id,
                    'order_id' => $order->getKey(),
                    'number' => $number,
                ],
                [
                    'client_id' => $order->client_id,
                    'external_id' => $externalId,
                    'status' => $this->string($data, 'status', 'situacao'),
                    'amount' => $this->decimal($data, 'amount', 'valor'),
                    'due_date' => $this->date($data, 'due_date', 'vencimento', 'dataVencimento'),
                    'paid_at' => $this->date($data, 'paid_at', 'dataPagamento', 'data_pagamento'),
                    'provenance' => $provenance,
                    'metadata' => $data,
                ],
            );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function normalizePayment(
        ParcelmentInstallment $installment,
        array $data,
        string $provenance = 'serpro',
    ): ParcelmentPayment {
        $externalId = $this->string($data, 'external_id', 'id', 'numero')
            ?? $this->string($data, 'numeroDas', 'numero_das')
            ?? $installment->external_id.'-payment';

        return ParcelmentPayment::query()
            ->withoutGlobalScope('account')
            ->updateOrCreate(
                [
                    'account_id' => $installment->account_id,
                    'installment_id' => $installment->getKey(),
                    'external_id' => $externalId,
                ],
                [
                    'client_id' => $installment->client_id,
                    'status' => $this->string($data, 'status', 'situacao'),
                    'amount' => $this->decimal($data, 'amount', 'valor'),
                    'paid_at' => $this->date($data, 'paid_at', 'dataPagamento', 'data_pagamento', 'dataArrecadacao'),
                    'receipt_ref' => $this->string($data, 'receipt_ref', 'comprovante', 'recibo'),
                    'provenance' => $provenance,
                    'metadata' => $data,
                ],
            );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function string(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function decimal(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function date(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            try {
                return Carbon::parse((string) $value)->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
