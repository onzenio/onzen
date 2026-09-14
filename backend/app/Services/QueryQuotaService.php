<?php

namespace App\Services;

use App\Models\Account;
use App\Models\MonitoringQuotaUsage;
use App\Models\MonitoringRun;
use Illuminate\Support\Facades\DB;

class QueryQuotaService
{
    public static function period(?\DateTimeInterface $date = null): string
    {
        return ($date ?? now())->format('Y-m');
    }

    public function volume(Account $account): int
    {
        return (int) ($account->plan?->monthly_query_volume ?? 0);
    }

    /**
     * @return array{volume: int, used: int, remaining: int, period: string}
     */
    public function balance(Account $account): array
    {
        $period = self::period();
        $volume = $this->volume($account->fresh());

        $used = MonitoringQuotaUsage::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('period', $period)
            ->count();

        return [
            'volume' => $volume,
            'used' => $used,
            'remaining' => max(0, $volume - $used),
            'period' => $period,
        ];
    }

    /**
     * Reserva uma unidade antes de qualquer tráfego. Atômica e idempotente
     * por execução: a mesma run nunca é cobrada duas vezes.
     */
    public function reserve(Account $account, MonitoringRun $run, string $origin = MonitoringRun::ORIGIN_MANUAL): void
    {
        DB::transaction(function () use ($account, $run, $origin): void {
            Account::query()->whereKey($account->id)->lockForUpdate()->first();

            $existing = MonitoringQuotaUsage::query()->withoutGlobalScopes()
                ->where('monitoring_run_id', $run->id)
                ->first();

            if ($existing) {
                return;
            }

            $balance = $this->balance($account);

            if ($balance['remaining'] <= 0) {
                throw new QuotaExhaustedException;
            }

            MonitoringQuotaUsage::query()->withoutGlobalScopes()->create([
                'account_id' => $account->id,
                'monitoring_run_id' => $run->id,
                'origin' => $origin,
                'period' => $balance['period'],
            ]);
        });
    }
}
