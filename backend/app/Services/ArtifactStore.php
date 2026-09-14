<?php

namespace App\Services;

interface ArtifactStore
{
    /**
     * @return array{ref: string, sha256: string, size: int}
     */
    public function put(int $accountId, string $contents, string $mime = 'application/pdf'): array;

    public function get(int $accountId, string $ref): ?string;

    public function meta(int $accountId, string $ref): ?array;

    public function delete(int $accountId, string $ref): void;
}
