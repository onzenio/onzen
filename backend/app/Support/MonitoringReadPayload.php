<?php

namespace App\Support;

use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use App\Models\ParcelmentInstallment;
use App\Models\ParcelmentOrder;
use App\Models\ParcelmentPayment;

/**
 * Payloads das leituras de monitoramento (Task 25).
 *
 * Uma única política de exposição para snapshots, mudanças, execuções,
 * alertas e parcelamentos (Task 26): metadados factuais, datas em ISO-8601 e
 * nenhum payload bruto, referência interna de armazenamento ou material
 * sensível. O `data` de um snapshot/mudança só aparece quando o resultado foi
 * normalizado; chaves internas (`*_storage_ref`, credenciais e conteúdo
 * binário pdf/xml/base64) são removidas recursivamente mesmo de resultados
 * normalizados. Nos parcelamentos o `metadata` cru nunca é exposto e as
 * referências de guia/comprovante aparecem apenas como fatos
 * (`guide_available`/`receipt_available`). O filtro é somente-leitura: nunca
 * altera o que está persistido.
 */
final class MonitoringReadPayload
{
    /**
     * Fragmentos de chave que nunca são expostos na leitura.
     *
     * @var list<string>
     */
    private const INTERNAL_KEY_FRAGMENTS = ['storage_ref', 'secret', 'password', 'pfx', 'token'];

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(MonitoringSnapshot $snapshot): array
    {
        $payload = [
            'id' => $snapshot->id,
            'enrollment_id' => $snapshot->enrollment_id,
            'client_id' => $snapshot->client_id,
            'run_id' => $snapshot->run_id,
            'operation_code' => $snapshot->operation_code,
            'family' => $snapshot->family,
            'normalized' => (bool) $snapshot->normalized,
            'fingerprint' => $snapshot->fingerprint,
            'freshness' => $snapshot->freshness,
            'completeness' => $snapshot->completeness,
            'verified_at' => $snapshot->verified_at?->toIso8601String(),
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ];

        if ($snapshot->normalized) {
            $payload['data'] = self::sanitize($snapshot->data);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function change(MonitoringChange $change): array
    {
        $snapshot = $change->relationLoaded('snapshot') ? $change->snapshot : null;
        $normalized = $snapshot?->normalized === true;

        $payload = [
            'id' => $change->id,
            'enrollment_id' => $change->enrollment_id,
            'client_id' => $change->client_id,
            'snapshot_id' => $change->snapshot_id,
            'previous_snapshot_id' => $change->previous_snapshot_id,
            'run_id' => $change->run_id,
            'operation_code' => $change->operation_code,
            'kind' => $change->kind,
            'normalized' => $normalized,
            'created_at' => $change->created_at?->toIso8601String(),
        ];

        if ($normalized) {
            $payload['data'] = self::sanitize($change->data);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(MonitoringRun $run): array
    {
        return [
            'id' => $run->id,
            'enrollment_id' => $run->enrollment_id,
            'definition_key' => $run->definition_key,
            'operation_code' => $run->operation_code,
            'trigger' => $run->trigger,
            'status' => $run->status->value,
            'environment' => $run->environment,
            'dry_run' => (bool) $run->dry_run,
            'protocol' => $run->protocol,
            'eta' => $run->eta?->toIso8601String(),
            'parameters' => $run->parameters === null ? null : self::sanitize($run->parameters),
            'external_code' => $run->external_code,
            'error_code' => $run->error_code,
            'fencing_token' => $run->fencing_token,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function alert(MonitoringAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'enrollment_id' => $alert->enrollment_id,
            'client_id' => $alert->client_id,
            'change_id' => $alert->change_id,
            'status' => $alert->status,
            'acknowledged_by_user_id' => $alert->acknowledged_by_user_id,
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }

    /**
     * Resumo normalizado de um pedido de parcelamento para listagem: os
     * agregados factuais derivam das parcelas quando carregadas; o
     * `metadata` bruto do provedor nunca aparece.
     *
     * @return array<string, mixed>
     */
    public static function parcelmentOrder(ParcelmentOrder $order): array
    {
        $installments = $order->relationLoaded('installments') ? $order->installments : null;

        $paidInstallments = $installments === null
            ? null
            : $installments->whereNotNull('paid_at')->count();

        $nextDue = $installments === null
            ? null
            : $installments->whereNull('paid_at')->sortBy('due_date')->first()?->due_date;

        return [
            'id' => $order->id,
            'client_id' => $order->client_id,
            'client' => self::clientSummary($order),
            'modality' => $order->modality,
            'external_id' => $order->external_id,
            'status' => $order->status,
            'installments_count' => $order->installments_count ?? $installments?->count(),
            'paid_installments' => $paidInstallments,
            'next_due_date' => $nextDue?->toDateString(),
            'total_amount' => $order->total_amount,
            'competence' => $order->competence?->toDateString(),
            'provenance' => $order->provenance,
            'operation_code' => $order->operation_code,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Detalhe normalizado do pedido com parcelas e pagamentos; as parcelas
     * precisam vir carregadas com `payments` pelo controller.
     *
     * @return array<string, mixed>
     */
    public static function parcelmentOrderDetail(ParcelmentOrder $order): array
    {
        $payload = self::parcelmentOrder($order);

        $payload['installments'] = $order->relationLoaded('installments')
            ? $order->installments
                ->map(fn (ParcelmentInstallment $installment): array => self::parcelmentInstallment($installment, true))
                ->values()
                ->all()
            : [];

        return $payload;
    }

    /**
     * Parcela normalizada; `guide_available` é o único sinal da guia — a
     * referência opaca do artefato fica interna.
     *
     * @return array<string, mixed>
     */
    public static function parcelmentInstallment(ParcelmentInstallment $installment, bool $withPayments = false): array
    {
        $payload = [
            'id' => $installment->id,
            'order_id' => $installment->order_id,
            'client_id' => $installment->client_id,
            'external_id' => $installment->external_id,
            'number' => $installment->number,
            'status' => $installment->status,
            'amount' => $installment->amount,
            'due_date' => $installment->due_date?->toDateString(),
            'paid_at' => $installment->paid_at?->toDateString(),
            'guide_available' => trim((string) $installment->guide_ref) !== '',
            'provenance' => $installment->provenance,
            'created_at' => $installment->created_at?->toIso8601String(),
            'updated_at' => $installment->updated_at?->toIso8601String(),
        ];

        if ($withPayments) {
            $payload['payments'] = $installment->relationLoaded('payments')
                ? $installment->payments
                    ->map(fn (ParcelmentPayment $payment): array => self::parcelmentPayment($payment))
                    ->values()
                    ->all()
                : [];
        }

        return $payload;
    }

    /**
     * Pagamento normalizado; `receipt_available` é o único sinal do
     * comprovante — a referência fica interna.
     *
     * @return array<string, mixed>
     */
    public static function parcelmentPayment(ParcelmentPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'installment_id' => $payment->installment_id,
            'client_id' => $payment->client_id,
            'external_id' => $payment->external_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'paid_at' => $payment->paid_at?->toDateString(),
            'receipt_available' => trim((string) $payment->receipt_ref) !== '',
            'provenance' => $payment->provenance,
            'created_at' => $payment->created_at?->toIso8601String(),
            'updated_at' => $payment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Resumo factual do Client vinculado (ou null quando não carregado).
     *
     * @return array{id: int, razao_social: string, cnpj: string}|null
     */
    private static function clientSummary(ParcelmentOrder $order): ?array
    {
        $client = $order->relationLoaded('client') ? $order->client : null;

        if ($client === null) {
            return null;
        }

        return [
            'id' => $client->id,
            'razao_social' => $client->razao_social,
            'cnpj' => $client->cnpj,
        ];
    }

    /**
     * Remove recursivamente chaves internas de um payload normalizado:
     * referências de armazenamento, credenciais e conteúdo binário.
     *
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>
     */
    public static function sanitize(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isInternalKey($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    private static function isInternalKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::INTERNAL_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return preg_match('/(^|_)(pdf|xml|base64)$/', $normalized) === 1;
    }
}
