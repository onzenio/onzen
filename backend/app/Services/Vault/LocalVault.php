<?php

namespace App\Services\Vault;

use App\Contracts\VaultResolver;
use App\Models\VaultEntry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

/**
 * Local vault backed by the application key.
 *
 * Refs are opaque and must start with `secret:`. Values are encrypted with
 * `Crypt` (APP_KEY) before touching the database; arrays are JSON-encoded
 * first and decoded on read. Account scope is encoded by callers inside the
 * ref itself (for example `secret:account-{accountId}-certificate`), so the
 * storage keeps treating refs as opaque identifiers.
 */
class LocalVault implements VaultResolver
{
    public const REF_PREFIX = 'secret:';

    private const TYPE_ARRAY = 'array';

    private const TYPE_STRING = 'string';

    public function put(string $ref, array|string $value): void
    {
        $this->assertRef($ref);

        [$type, $payload] = is_array($value)
            ? [self::TYPE_ARRAY, json_encode($value, JSON_THROW_ON_ERROR)]
            : [self::TYPE_STRING, $value];

        VaultEntry::query()->updateOrCreate(
            ['ref' => $ref],
            ['type' => $type, 'value' => Crypt::encryptString($payload)],
        );
    }

    public function get(string $ref): array|string|null
    {
        $this->assertRef($ref);

        $entry = VaultEntry::query()->find($ref);

        if ($entry === null) {
            return null;
        }

        try {
            $payload = Crypt::decryptString($entry->value);
        } catch (DecryptException) {
            return null;
        }

        if ($entry->type !== self::TYPE_ARRAY) {
            return $payload;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function forget(string $ref): void
    {
        $this->assertRef($ref);

        VaultEntry::query()->where('ref', $ref)->delete();
    }

    private function assertRef(string $ref): void
    {
        if ($ref === self::REF_PREFIX || ! str_starts_with($ref, self::REF_PREFIX)) {
            throw new InvalidArgumentException("Vault refs must start with '".self::REF_PREFIX."'.");
        }
    }
}
