<?php

namespace App\Services;

use App\Models\Account;
use App\Models\MonitoringRun;
use App\Models\User;

class MonitoringRunService
{
    /**
     * Cria ou retorna a execução existente (idempotência por chave).
     *
     * @param array{client_id?: int|null, definition_code: string, origin?: string, fencing_token?: int} $attrs
     */
    public function startOrGet(Account $account, string $idempotencyKey, array $attrs, ?User $actor = null): MonitoringRun
    {
        $existing = MonitoringRun::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        return MonitoringRun::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'client_id' => $attrs['client_id'] ?? null,
            'definition_code' => $attrs['definition_code'],
            'status' => MonitoringRun::PENDING,
            'idempotency_key' => $idempotencyKey,
            'fencing_token' => $attrs['fencing_token'] ?? 1,
            'origin' => $attrs['origin'] ?? MonitoringRun::ORIGIN_MANUAL,
            'triggered_by' => $actor?->id,
        ]);
    }

    /**
     * Descarta resultado de execução superada (fencing): marca expirada.
     */
    public function discardIfStale(MonitoringRun $run, int $currentVersion): bool
    {
        if ($run->fencing_token >= $currentVersion || $run->isTerminal()) {
            return false;
        }

        $run->forceFill(['status' => MonitoringRun::EXPIRED])->save();

        return true;
    }
}
