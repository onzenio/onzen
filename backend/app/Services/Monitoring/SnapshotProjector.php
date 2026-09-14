<?php

namespace App\Services\Monitoring;

use App\Contracts\ResultProjector;
use App\Enums\MonitoringRunStatus;
use App\Integrations\Serpro\ConsultMessageClassifier;
use App\Integrations\Serpro\FamilyConsultNormalizer;
use App\Integrations\Serpro\ResponseClassifier;
use App\Integrations\Serpro\SerproClassification;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Projetor real dos resultados de consulta (Task 23).
 *
 * Normaliza o corpo pela família (Task 22), calcula o fingerprint determinístico
 * do resultado e mantém snapshots versionados por Associação + operação:
 *
 * - repetir o mesmo fingerprint atualiza `verified_at` da versão vigente sem
 *   criar versão, mudança ou alerta;
 * - fingerprint diferente publica exatamente um snapshot, uma mudança e um
 *   alerta, tudo na mesma transação; redelivery do mesmo run é no-op
 *   (`run_id` único);
 * - resultados incompletos (aguardando protocolo), stale (protocolo expirado)
 *   ou bloqueados (transporte/quota/rejeição) nunca publicam; o estado
 *   corrente fica visível pela última run e por {@see self::stateFor()}.
 *
 * O fingerprint cobre o conteúdo fiscal (`operation_code`, família, flag de
 * normalização e dados) e exclui `provenance`/`catalog_version`: a mesma
 * resposta vinda de fixture ou de transporte, e a mesma resposta sob outra
 * geração de catálogo, não versam de novo. Nunca há payload bruto em log.
 */
final class SnapshotProjector implements ResultProjector
{
    private const OUTCOME_COMPLETE = 'complete';

    private const OUTCOME_INCOMPLETE = 'incomplete';

    private const OUTCOME_STALE = 'stale';

    private const OUTCOME_BLOCKED = 'blocked';

    public function __construct(
        private readonly FamilyConsultNormalizer $normalizer,
        private readonly ResponseClassifier $classifier,
        private readonly ConsultMessageClassifier $messages,
    ) {}

    /**
     * Project a completed result into the snapshot/change/alert chain.
     *
     * Callable more than once per run: re-delivery converges on the already
     * published version (`run_id` unique) instead of duplicating anything.
     *
     * @param  array<string, mixed>  $result
     */
    public function project(MonitoringRun $run, array $result): void
    {
        $outcome = $this->outcome($result);

        if ($outcome !== self::OUTCOME_COMPLETE) {
            // Fail-closed: incomplete/stale/blocked outcomes never publish a
            // complete snapshot nor a change/alert. An expired protocol still
            // marks the last complete version stale so freshness stays
            // factual for reads.
            if ($outcome === self::OUTCOME_STALE) {
                $this->markStale($run, (string) ($result['operation_code'] ?? $run->operation_code ?? ''));
            }

            return;
        }

        $envelope = $this->normalizer->normalize(
            (string) ($result['operation_code'] ?? $run->operation_code ?? ''),
            is_array($result['body'] ?? null) ? $result['body'] : [],
            (string) ($result['source'] ?? 'serpro'),
        );

        $operationCode = $envelope['operation_code'];

        if ($operationCode === '') {
            // Fail-closed: a successful body without an operation code is not
            // attributable and never versions.
            return;
        }

        $fingerprint = $this->fingerprint($envelope);

        try {
            DB::transaction(function () use ($run, $envelope, $operationCode, $fingerprint): void {
                // Serialize projections per enrollment: concurrent runs must
                // see the committed current version instead of racing inserts.
                $enrollment = MonitoringEnrollment::query()
                    ->withoutGlobalScope('account')
                    ->where('account_id', $run->account_id)
                    ->whereKey($run->enrollment_id)
                    ->lockForUpdate()
                    ->first();

                if ($enrollment === null) {
                    return;
                }

                if (MonitoringSnapshot::query()->withoutGlobalScope('account')->where('run_id', $run->getKey())->exists()) {
                    return; // Re-delivery of an already projected run.
                }

                $current = $this->currentFor($run, $operationCode);

                if ($current !== null && $current->fingerprint === $fingerprint) {
                    // Same result: refresh the verification of the current
                    // version, never publish a new one.
                    $current->forceFill([
                        'verified_at' => now(),
                        'freshness' => MonitoringSnapshot::FRESHNESS_FRESH,
                    ])->save();

                    return;
                }

                $snapshot = MonitoringSnapshot::query()->create([
                    'account_id' => $run->account_id,
                    'enrollment_id' => $run->enrollment_id,
                    'client_id' => $enrollment->client_id,
                    'run_id' => $run->getKey(),
                    'operation_code' => $operationCode,
                    'family' => $envelope['family'],
                    'normalized' => $envelope['normalized'],
                    'fingerprint' => $fingerprint,
                    'data' => $envelope['data'],
                    'freshness' => MonitoringSnapshot::FRESHNESS_FRESH,
                    'completeness' => MonitoringSnapshot::COMPLETENESS_COMPLETE,
                    'verified_at' => now(),
                ]);

                if ($current === null) {
                    // First published version: nothing changed yet.
                    return;
                }

                MonitoringSnapshot::query()
                    ->withoutGlobalScope('account')
                    ->where('enrollment_id', $run->enrollment_id)
                    ->where('operation_code', $operationCode)
                    ->whereKeyNot($snapshot->getKey())
                    ->update(['freshness' => MonitoringSnapshot::FRESHNESS_STALE]);

                $change = MonitoringChange::query()->create([
                    'account_id' => $run->account_id,
                    'enrollment_id' => $run->enrollment_id,
                    'client_id' => $enrollment->client_id,
                    'snapshot_id' => $snapshot->getKey(),
                    'previous_snapshot_id' => $current->getKey(),
                    'run_id' => $run->getKey(),
                    'operation_code' => $operationCode,
                    'kind' => MonitoringChange::KIND_CHANGED,
                    'data' => [
                        'before' => $current->data,
                        'after' => $envelope['data'],
                    ],
                ]);

                MonitoringAlert::query()->create([
                    'account_id' => $run->account_id,
                    'enrollment_id' => $run->enrollment_id,
                    'client_id' => $enrollment->client_id,
                    'change_id' => $change->getKey(),
                    'status' => MonitoringAlert::STATUS_PENDING,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery won the insert for this run/mudança: the
            // outcome is already persisted and re-projection converges.
        }
    }

    /**
     * Estado corrente para leitura (snapshot vigente + última execução):
     * freshness e completude factuais e a cobertura publicada por operação.
     *
     * @return array{
     *     freshness: string,
     *     completeness: string,
     *     coverage: array{operations: list<string>, families: list<string>},
     *     verified_at: string|null
     * }
     */
    public function stateFor(MonitoringEnrollment $enrollment, ?string $operationCode = null): array
    {
        $snapshots = MonitoringSnapshot::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $enrollment->account_id)
            ->where('enrollment_id', $enrollment->getKey())
            ->when($operationCode !== null, fn ($query) => $query->where('operation_code', $operationCode))
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->get();

        $current = $snapshots->first();

        $latestRun = MonitoringRun::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $enrollment->account_id)
            ->where('enrollment_id', $enrollment->getKey())
            ->when($operationCode !== null, fn ($query) => $query->where('operation_code', $operationCode))
            ->orderByDesc('id')
            ->first();

        return [
            'freshness' => $current?->freshness ?? MonitoringSnapshot::FRESHNESS_STALE,
            'completeness' => $this->completenessFor($current, $latestRun),
            'coverage' => [
                'operations' => $snapshots->pluck('operation_code')->unique()->values()->all(),
                'families' => $snapshots->pluck('family')->unique()->values()->all(),
            ],
            'verified_at' => $current?->verified_at?->toIso8601String(),
        ];
    }

    /**
     * The current version of an operation for the enrollment: the most
     * recently verified snapshot, locked inside the projection transaction.
     */
    private function currentFor(MonitoringRun $run, string $operationCode): ?MonitoringSnapshot
    {
        return MonitoringSnapshot::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->where('enrollment_id', $run->enrollment_id)
            ->where('operation_code', $operationCode)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Mark the current version of an operation stale without touching its
     * data: the provider confirmed the verification can no longer be trusted
     * as current.
     */
    private function markStale(MonitoringRun $run, string $operationCode): void
    {
        $operationCode = strtoupper(trim($operationCode));

        if ($operationCode === '') {
            return;
        }

        MonitoringSnapshot::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->where('enrollment_id', $run->enrollment_id)
            ->where('operation_code', $operationCode)
            ->update(['freshness' => MonitoringSnapshot::FRESHNESS_STALE]);
    }

    /**
     * Complete results publish; awaiting protocol is incomplete, an expired
     * protocol is stale and everything else (transport, quota, rejection) is
     * blocked.
     *
     * @param  array<string, mixed>  $result
     */
    private function outcome(array $result): string
    {
        $status = (int) ($result['http_status'] ?? 0);
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];

        $classification = $this->messages->classify(
            $body,
            $this->classifier->classify($status, $body, $headers),
        );

        return match ($classification->status) {
            SerproClassification::SUCCESS => self::OUTCOME_COMPLETE,
            SerproClassification::AWAITING_PROTOCOL => self::OUTCOME_INCOMPLETE,
            SerproClassification::EXPIRED => self::OUTCOME_STALE,
            default => self::OUTCOME_BLOCKED,
        };
    }

    private function completenessFor(?MonitoringSnapshot $current, ?MonitoringRun $run): string
    {
        if ($run === null) {
            return $current?->completeness ?? MonitoringSnapshot::COMPLETENESS_INCOMPLETE;
        }

        return match ($run->status) {
            // Fail-closed: a completed run without a published snapshot is
            // never reported as complete; only a real snapshot does.
            MonitoringRunStatus::Completed => $current?->completeness ?? MonitoringSnapshot::COMPLETENESS_INCOMPLETE,
            MonitoringRunStatus::Pending,
            MonitoringRunStatus::Running,
            MonitoringRunStatus::AwaitingProtocol,
            MonitoringRunStatus::Limited,
            MonitoringRunStatus::Transient,
            MonitoringRunStatus::Expired => MonitoringSnapshot::COMPLETENESS_INCOMPLETE,
            default => MonitoringSnapshot::COMPLETENESS_BLOCKED,
        };
    }

    /**
     * Deterministic fingerprint of the fiscal content. Recursively sorts
     * object keys so provider key order never versions a snapshot, and
     * excludes `provenance`/`catalog_version` so the same payload does not
     * version merely because it came from a fixture or another catalog
     * generation.
     *
     * @param  array<string, mixed>  $envelope
     *
     * @throws JsonException
     */
    private function fingerprint(array $envelope): string
    {
        unset($envelope['provenance'], $envelope['catalog_version']);

        return hash('sha256', json_encode(
            $this->canonicalize($envelope),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
