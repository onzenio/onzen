<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Regista um evento de Audit em best-effort: nunca lança.
     * Falha de persistência retorna null + log técnico.
     */
    public function record(
        ?User $actor,
        ?int $originAccountId,
        ?int $targetAccountId,
        string $action,
        array $metadata = [],
    ): ?AuditLog {
        $originAccountId ??= CurrentAccount::get() ?? $actor?->account_id;

        if ($originAccountId === null) {
            Log::warning('audit.record_skipped', [
                'action' => $action,
                'reason' => 'missing_origin_account',
            ]);

            return null;
        }

        try {
            return AuditLog::query()->create([
                'actor_user_id' => $actor?->id,
                'origin_account_id' => $originAccountId,
                'target_account_id' => $targetAccountId,
                'action' => $action,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit.record_failed', [
                'action' => $action,
                'origin_account_id' => $originAccountId,
                'target_account_id' => $targetAccountId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
