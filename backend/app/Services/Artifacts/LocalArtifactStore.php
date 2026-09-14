<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactStore;
use App\Exceptions\ArtifactStorageUnavailableException;
use App\Models\MonitoringArtifact;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Private on-disk artifact store.
 *
 * The `local` disk root is `storage/app/private`, so contents live under
 * `storage/app/private/monitoring/{account}/{ref}` — outside the web root.
 * Writes go to a temp path first and are moved into place, so a partially
 * written artifact is never published under its final ref.
 */
class LocalArtifactStore implements ArtifactStore
{
    public const DISK = 'local';

    public const ROOT = 'monitoring';

    public function put(string $contents, array $metadata = []): array
    {
        $accountId = $this->resolveAccountId($metadata);
        $ref = (string) Str::ulid();
        $hash = hash('sha256', $contents);
        $path = self::ROOT.'/'.$accountId.'/'.$ref;

        $this->write($path, $contents);

        try {
            MonitoringArtifact::query()->create([
                'ref' => $ref,
                'account_id' => $accountId,
                'client_id' => $metadata['client_id'] ?? null,
                'enrollment_id' => $metadata['enrollment_id'] ?? null,
                'kind' => $metadata['kind'] ?? 'other',
                'source' => $metadata['source'] ?? null,
                'original_name' => $metadata['original_name'] ?? null,
                'storage_path' => $path,
                'hash_sha256' => $hash,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->forgetFile($path);

            throw $exception;
        }

        return ['ref' => $ref, 'hash_sha256' => $hash];
    }

    public function get(string $ref): ?string
    {
        $artifact = $this->find($ref);

        if ($artifact === null) {
            return null;
        }

        $disk = $this->disk();

        try {
            if (! $disk->exists($artifact->storage_path)) {
                return null;
            }

            $contents = $disk->get($artifact->storage_path);
        } catch (Throwable $exception) {
            // A delete between exists() and get() is a confirmed absence;
            // a second failed existence check is a storage outage.
            try {
                if (! $disk->exists($artifact->storage_path)) {
                    return null;
                }
            } catch (Throwable) {
                // Fall through to the unavailable classification.
            }

            throw new ArtifactStorageUnavailableException('Artifact storage is temporarily unavailable.', 0, $exception);
        }

        return is_string($contents) ? $contents : null;
    }

    public function delete(string $ref): bool
    {
        $artifact = $this->find($ref);

        if ($artifact === null) {
            return false;
        }

        try {
            $this->disk()->delete($artifact->storage_path);
        } catch (Throwable $exception) {
            throw new ArtifactStorageUnavailableException('Artifact storage is temporarily unavailable.', 0, $exception);
        }

        $artifact->delete();

        return true;
    }

    public function exists(string $ref): bool
    {
        $artifact = $this->find($ref);

        if ($artifact === null) {
            return false;
        }

        try {
            return $this->disk()->exists($artifact->storage_path);
        } catch (Throwable $exception) {
            throw new ArtifactStorageUnavailableException('Artifact storage is temporarily unavailable.', 0, $exception);
        }
    }

    private function find(string $ref): ?MonitoringArtifact
    {
        return MonitoringArtifact::query()
            ->withoutGlobalScope('account')
            ->where('ref', $ref)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveAccountId(array $metadata): int
    {
        $accountId = $metadata['account_id'] ?? CurrentAccount::get();

        if (! is_numeric($accountId)) {
            throw new InvalidArgumentException('An Account context is required to store artifacts.');
        }

        return (int) $accountId;
    }

    private function write(string $path, string $contents): void
    {
        $disk = $this->disk();
        $temp = dirname($path).'/.'.basename($path).'.tmp'.bin2hex(random_bytes(4));

        try {
            if (! $disk->put($temp, $contents)) {
                throw new ArtifactStorageUnavailableException('Artifact storage is temporarily unavailable.');
            }

            if (! $disk->move($temp, $path)) {
                throw new ArtifactStorageUnavailableException('Artifact storage is temporarily unavailable.');
            }
        } catch (Throwable $exception) {
            $this->forgetFile($temp);

            if ($exception instanceof ArtifactStorageUnavailableException) {
                throw $exception;
            }

            throw new ArtifactStorageUnavailableException('Artifact storage is temporarily unavailable.', 0, $exception);
        }
    }

    private function forgetFile(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable) {
            // Best-effort cleanup; never mask the original failure.
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
