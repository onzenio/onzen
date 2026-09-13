<?php

namespace App\Contracts;

use App\Exceptions\ArtifactStorageUnavailableException;

/**
 * Private artifact storage behind an abstraction.
 *
 * Implementations persist bytes outside the web root and expose only an
 * opaque public `ref` plus the SHA-256 of the stored contents; the physical
 * path never leaves the implementation.
 */
interface ArtifactStore
{
    /**
     * Store contents and return the opaque public reference and SHA-256.
     *
     * Accepted metadata keys: `account_id`, `client_id`, `enrollment_id`,
     * `kind`, `source`, `original_name`. When `account_id` is absent the
     * current Account context is used.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{ref: string, hash_sha256: string}
     *
     * @throws ArtifactStorageUnavailableException when the storage is unreachable
     */
    public function put(string $contents, array $metadata = []): array;

    /**
     * Read contents by opaque ref; null when the artifact does not exist.
     *
     * @throws ArtifactStorageUnavailableException when the storage is unreachable
     */
    public function get(string $ref): ?string;

    /**
     * Remove the contents and the metadata row; false when the ref is unknown.
     *
     * @throws ArtifactStorageUnavailableException when the storage is unreachable
     */
    public function delete(string $ref): bool;

    /**
     * @throws ArtifactStorageUnavailableException when the storage is unreachable
     */
    public function exists(string $ref): bool;
}
