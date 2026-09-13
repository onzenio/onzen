<?php

namespace App\Services\Monitoring;

use App\Exceptions\SerproBlockedException;
use App\Models\Account;
use App\Models\MonitoringRun;
use App\Models\QueryQuotaConsumption;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quota agregada do Plan (Task 17 / design decision 7).
 *
 * Cada Account consome `Plan.monthly_query_volume` por ciclo mensal
 * (America/Sao_Paulo). A reserva é atômica: a row da Account é travada, o
 * consumo do período é contado e a unidade só é gravada se houver saldo —
 * duas reservas concorrentes nunca ultrapassam o teto. O `run_id` é unique,
 * então repetir a reserva do mesmo run (retry, poll, replay) é sucesso e não
 * um segundo débito.
 *
 * Fail-closed: Account sem Plan tem limite zero e é bloqueada com 422 e
 * mensagem de upgrade, sem consumo e sem tráfego. A leitura é sempre
 * account-scoped, sem vazar dados entre Accounts.
 */
final class QueryQuotaService
{
    public const ERROR_EXCEEDED = 'quota_exceeded';

    private const TIMEZONE = 'America/Sao_Paulo';

    /**
     * Reserve one unit of the Plan volume for a run.
     *
     * Idempotent by `run_id`: an existing consumption row means the run was
     * already charged and returns successfully. A run that does not fit the
     * remaining volume raises a 422 {@see ValidationException} with an
     * upgrade-oriented message and leaves no consumption behind.
     *
     * @throws ValidationException
     * @throws SerproBlockedException
     */
    public function reserve(Account $account, MonitoringRun $run): void
    {
        // A run may only be charged to its own Account: the consumption is
        // the account-scoped truth for replays, so a mismatched caller would
        // write the debit under the wrong Account and let the real one replay
        // for free. Fail closed with a factual code.
        if ((int) $run->account_id !== (int) $account->getKey()) {
            throw new SerproBlockedException('quota_account_mismatch');
        }

        try {
            $this->reserveInTransaction($account, $run);
        } catch (UniqueConstraintViolationException $exception) {
            // Two transactions raced past the replay check: the unique
            // `run_id` means one of them won and its committed row IS the
            // reservation. This call is a replay, not a failure — unless no
            // row survived, which keeps the original error factual.
            $reserved = QueryQuotaConsumption::query()
                ->withoutGlobalScope('account')
                ->where('run_id', $run->getKey())
                ->exists();

            if (! $reserved) {
                throw $exception;
            }
        }
    }

    /**
     * @throws ValidationException
     * @throws SerproBlockedException
     */
    private function reserveInTransaction(Account $account, MonitoringRun $run): void
    {
        DB::transaction(function () use ($account, $run): void {
            $accountId = (int) $account->getKey();

            // Lock first: every reservation for this Account serializes here,
            // so the count below can never race an insert past the ceiling.
            $locked = Account::query()->whereKey($accountId)->lockForUpdate()->first();

            if ($locked === null) {
                throw new SerproBlockedException('account_missing');
            }

            $alreadyReserved = QueryQuotaConsumption::query()
                ->withoutGlobalScope('account')
                ->where('run_id', $run->getKey())
                ->exists();

            if ($alreadyReserved) {
                return;
            }

            $period = $this->period();
            $limit = $this->limitFor($locked);
            $consumed = $this->consumedFor($accountId, $period);

            if ($consumed >= $limit) {
                throw $this->exceeded($consumed, $limit, $period);
            }

            QueryQuotaConsumption::query()->create([
                'account_id' => $accountId,
                'run_id' => $run->getKey(),
                'trigger' => $this->triggerFor($run),
                'period' => $period,
            ]);
        });
    }

    /**
     * Saldo consultável da Account no ciclo corrente.
     *
     * @return array{period: string, consumed: int, limit: int}
     */
    public function usage(Account $account): array
    {
        $period = $this->period();

        return [
            'period' => $period,
            'consumed' => $this->consumedFor((int) $account->getKey(), $period),
            'limit' => $this->limitFor($account),
        ];
    }

    private function consumedFor(int $accountId, string $period): int
    {
        return QueryQuotaConsumption::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('period', $period)
            ->count();
    }

    /**
     * Fail-closed: an Account without a Plan has no volume at all.
     */
    private function limitFor(Account $account): int
    {
        return max(0, (int) ($account->plan?->monthly_query_volume ?? 0));
    }

    private function triggerFor(MonitoringRun $run): string
    {
        return trim((string) $run->trigger) === MonitoringRun::TRIGGER_AUTOMATIC
            ? MonitoringRun::TRIGGER_AUTOMATIC
            : MonitoringRun::TRIGGER_MANUAL;
    }

    private function period(): string
    {
        return Carbon::now(self::TIMEZONE)->format('Y-m');
    }

    private function exceeded(int $consumed, int $limit, string $period): ValidationException
    {
        return ValidationException::withMessages([
            'quota' => sprintf(
                'Volume de consultas do plano esgotado no período %s (%d/%d). Faça upgrade do plano para continuar consultando.',
                $period,
                $consumed,
                $limit,
            ),
        ]);
    }
}
