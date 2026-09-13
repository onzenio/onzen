<?php

namespace App\Services;

use App\Enums\AccountProfile;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentAccount;
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
    public const REDACTED = '[redacted]';

    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = ['password', 'secret', 'pfx', 'token'];

    /**
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

            $log = new AuditLog([
                'actor_user_id' => $actor?->id,
                'origin_account_id' => $origin->id,
                'target_account_id' => null,
                'action' => $action,
                'metadata' => $this->redact($metadata),
            ]);
            $log->created_at = now();
            $log->save();

            return $log;
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

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function redact(array $metadata): array
    {
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
