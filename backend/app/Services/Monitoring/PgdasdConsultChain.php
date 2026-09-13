<?php

namespace App\Services\Monitoring;

use App\Enums\MonitoringRunStatus;
use App\Integrations\Serpro\ConsultCatalog;
use App\Integrations\Serpro\FamilyConsultNormalizer;
use App\Jobs\ExecuteSerproJob;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;
use Illuminate\Support\Carbon;

/**
 * Cadeia consultiva PGDAS-D (Task 24 / design decision 9).
 *
 * Porta a regra do `_legacy`: quando a projeção do índice oficial
 * `CONSDECLARACAO13` publica um snapshot novo ou alterado, as consultas
 * seguintes da cadeia são enfileiradas para a mesma Associação:
 *
 * - `CONSDECREC15` (`numeroDeclaracao`/`periodoApuracao`) quando o índice traz
 *   o número da declaração;
 * - `CONSULTIMADECREC14` (`anoCalendario`) como fallback quando não há número
 *   de declaração;
 * - `CONSEXTRATO16` (`numeroDas`) quando o índice traz o número do DAS.
 *
 * A novidade é o próprio snapshot publicado para a run do índice: o
 * `SnapshotProjector` só grava um snapshot com `run_id` da run quando o
 * fingerprint é novo ou mudou, e uma repetição sem mudança apenas atualiza
 * `verified_at` — logo uma redelivery/repetição não encadeia de novo. O
 * enfileiramento é idempotente por `Account + idempotency_key` (hora de
 * negócio), então replay da cadeia não duplica runs. A cadeia nunca chama o
 * provedor: apenas cria runs `pending` e despacha o job por id opaco com
 * `afterCommit`; quota, elegibilidade e fencing são aplicados pelo
 * {@see SerproExecutor} quando a run filha executa, com o mesmo fencing token
 * da run do índice.
 *
 * PJ-only não é re-checado aqui: o `_legacy` não o fazia na cadeia, e o
 * catálogo/Associação já garantem que `pgdas-declaracoes` só existe para PJ.
 */
final class PgdasdConsultChain
{
    public const INDEX_OPERATION = 'CONSDECLARACAO13';

    public const DECLARATION_OPERATION = 'CONSDECREC15';

    public const LATEST_DECLARATION_OPERATION = 'CONSULTIMADECREC14';

    public const EXTRACT_OPERATION = 'CONSEXTRATO16';

    private const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        private readonly FamilyConsultNormalizer $normalizer,
    ) {}

    /**
     * Enfileira os passos seguintes da cadeia após a projeção do índice.
     *
     * Deve ser chamada dentro da transação de conclusão da run do índice: a
     * novidade é detectada pelo snapshot publicado com o `run_id` da run.
     *
     * @param  array{
     *     source?: string,
     *     operation_code?: string,
     *     body?: array<string, mixed>
     * }  $result
     */
    public function enqueueAfterIndex(MonitoringRun $indexRun, MonitoringEnrollment $enrollment, array $result): void
    {
        if (! $this->isPgdasdIndexRun($indexRun)
            || ! $enrollment->isActive()
            || ! $this->publishedFor($indexRun)
        ) {
            return;
        }

        $normalized = $this->normalizer->normalize(
            self::INDEX_OPERATION,
            is_array($result['body'] ?? null) ? $result['body'] : [],
            (string) ($result['source'] ?? 'serpro'),
        );

        $data = is_array($normalized['data'] ?? null) ? $normalized['data'] : [];
        $declaration = $this->declaration($data);

        $numeroDeclaracao = $this->string($declaration, 'numero_declaracao', 'numeroDeclaracao');
        $numeroDas = $this->string($declaration, 'numero_das', 'numeroDas');
        $anoCalendario = $this->string($declaration, 'ano_calendario', 'anoCalendario')
            ?? $this->string($data, 'ano_calendario', 'anoCalendario');

        if ($numeroDeclaracao !== null) {
            $this->enqueue($indexRun, $enrollment, self::DECLARATION_OPERATION, [
                'numeroDeclaracao' => $numeroDeclaracao,
                'periodoApuracao' => $this->string($declaration, 'periodo_apuracao', 'periodoApuracao'),
            ]);
        } elseif ($anoCalendario !== null) {
            $this->enqueue($indexRun, $enrollment, self::LATEST_DECLARATION_OPERATION, [
                'anoCalendario' => $anoCalendario,
            ]);
        }

        if ($numeroDas !== null) {
            $this->enqueue($indexRun, $enrollment, self::EXTRACT_OPERATION, [
                'numeroDas' => $numeroDas,
            ]);
        }
    }

    /**
     * Só a definição PGDAS-D do catálogo e a operação de índice encadeiam.
     */
    private function isPgdasdIndexRun(MonitoringRun $run): bool
    {
        if (strtoupper(trim((string) $run->operation_code)) !== self::INDEX_OPERATION) {
            return false;
        }

        $operations = ConsultCatalog::operationsFor((string) $run->definition_key);

        return is_array($operations) && in_array(self::INDEX_OPERATION, $operations, true);
    }

    /**
     * A projeção publicou um snapshot para esta run? Snapshot com `run_id` da
     * run significa fingerprint novo (primeira versão) ou alterado; uma
     * repetição sem mudança não cria snapshot.
     */
    private function publishedFor(MonitoringRun $run): bool
    {
        return MonitoringSnapshot::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $run->account_id)
            ->where('run_id', $run->getKey())
            ->exists();
    }

    /**
     * Cria a run filha uma única vez por hora de negócio e a despacha.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function enqueue(
        MonitoringRun $parent,
        MonitoringEnrollment $enrollment,
        string $operation,
        array $parameters,
    ): void {
        $identifier = (string) ($parameters['numeroDeclaracao']
            ?? $parameters['numeroDas']
            ?? $parameters['anoCalendario']
            ?? '');

        $key = implode(':', [
            'chain',
            (string) $enrollment->getKey(),
            (string) $parent->fencing_token,
            $operation,
            $identifier,
            Carbon::now(self::TIMEZONE)->format('Y-m-d-H'),
        ]);

        $run = MonitoringRun::query()
            ->withoutGlobalScope('account')
            ->firstOrCreate(
                [
                    'account_id' => $enrollment->account_id,
                    'idempotency_key' => $key,
                ],
                [
                    'enrollment_id' => $enrollment->getKey(),
                    'trigger' => MonitoringRun::TRIGGER_AUTOMATIC,
                    'definition_key' => (string) $enrollment->definition_id,
                    'operation_code' => $operation,
                    'fencing_token' => (int) $parent->fencing_token,
                    'status' => MonitoringRunStatus::Pending,
                    'environment' => (string) $parent->environment,
                    'dry_run' => (bool) $parent->dry_run,
                    'parameters' => $parameters,
                    'external_code' => $operation,
                ],
            );

        if ($run->wasRecentlyCreated) {
            ExecuteSerproJob::dispatch((int) $run->getKey())->afterCommit();
        }
    }

    /**
     * A declaração do índice: a primeira linha de `declaracoes`, com os
     * campos de topo do `data` (ex.: `ano_calendario`) como fallback.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function declaration(array $data): array
    {
        $rows = $data['declaracoes'] ?? null;

        if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
            return $rows[0] + array_filter($data, fn (mixed $key): bool => $key !== 'declaracoes', ARRAY_FILTER_USE_KEY);
        }

        return $data;
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
}
