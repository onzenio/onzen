<?php

namespace App\Services;

use App\Models\Account;
use App\Models\VaultSecret;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class VaultService
{
    public function put(Account $account, string $name, string $plaintext): string
    {
        VaultSecret::query()->withoutGlobalScopes()->updateOrCreate(
            ['account_id' => $account->id, 'name' => $name],
            ['ciphertext' => Crypt::encryptString($plaintext)],
        );

        Log::info('vault.secret_stored', [
            'account_id' => $account->id,
            'name' => $name,
        ]);

        return $this->ref($account, $name);
    }

    public function get(string $ref): ?string
    {
        $parsed = $this->parse($ref);

        if ($parsed === null) {
            return null;
        }

        [$accountId, $name] = $parsed;

        $secret = VaultSecret::query()->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('name', $name)
            ->first();

        if ($secret === null) {
            return null;
        }

        try {
            return Crypt::decryptString($secret->ciphertext);
        } catch (\Throwable $e) {
            Log::warning('vault.decrypt_failed', ['account_id' => $accountId, 'name' => $name]);

            return null;
        }
    }

    public function forget(Account $account, string $name): void
    {
        VaultSecret::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('name', $name)
            ->delete();
    }

    public function ref(Account $account, string $name): string
    {
        return "secret:{$account->id}:{$name}";
    }

    /**
     * @return array{int, string}|null
     */
    public function parse(string $ref): ?array
    {
        if (! str_starts_with($ref, 'secret:')) {
            return null;
        }

        $parts = explode(':', $ref, 3);

        if (count($parts) !== 3 || ! is_numeric($parts[1]) || $parts[2] === '') {
            return null;
        }

        return [(int) $parts[1], $parts[2]];
    }

    /**
     * Mascara refs opacas em payloads destinados a log/API.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'secret:')) {
                $payload[$key] = 'secret:***';
            } elseif (is_string($value) && in_array(strtolower($key), ['token', 'password', 'pfx', 'secret', 'consumer_secret'], true)) {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }
}
