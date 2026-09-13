<?php

namespace App\Services;

use App\Enums\AccountProfile;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\Redactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Bridge until the multi-account AuditObserver lands.
 *
 * Best-effort by design: auditing never breaks the operation it records.
 * Metadata is redacted and never logged, so secrets stay out of both storage
 * and the technical failure log.
 */
class AuditService
{
    public const REDACTED = Redactor::REDACTED;

    /**
     * Record an audit entry. Canonical contract:
     * `record(?User $actor, string $action, array $metadata = [], ?Account $account = null)`.
     *
     * Callers pass the affected Account as `$account`; origin resolution order
     * is `$account` → actor's account → CurrentAccount → platform Account A.
     *
     * Best-effort: returns the AuditLog on success and null when auditing
     * fails (technical error is logged, secrets are never logged). The insert
     * runs in a nested transaction (savepoint when already inside one) so a
     * failed audit never aborts the caller's transaction on PostgreSQL.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(?User $actor, string $action, array $metadata = [], ?Account $account = null): ?AuditLog
    {
        try {
            $origin = $account
                ?? $actor?->account
                ?? $this->resolveCurrentAccount()
                ?? $this->resolvePlatformAccount();

            if ($origin === null) {
                throw new RuntimeException('No origin Account available to record the audit entry.');
            }

            return DB::transaction(function () use ($actor, $origin, $action, $metadata): AuditLog {
                $log = new AuditLog([
                    'actor_user_id' => $actor?->id,
                    'origin_account_id' => $origin->id,
                    'target_account_id' => null,
                    'action' => $action,
                    'metadata' => Redactor::array($metadata),
                ]);
                $log->created_at = now();
                $log->save();

                return $log;
            });
        } catch (Throwable $exception) {
            Log::error('audit.record_failed', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveCurrentAccount(): ?Account
    {
        $accountId = CurrentAccount::get();

        return $accountId === null ? null : Account::query()->find($accountId);
    }

    private function resolvePlatformAccount(): ?Account
    {
        return Account::query()
            ->where('profile', AccountProfile::A)
            ->orderBy('id')
            ->first();
    }
}
