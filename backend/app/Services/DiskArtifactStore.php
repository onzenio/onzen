<?php

namespace App\Services;

use App\Models\MonitoringArtifact;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DiskArtifactStore implements ArtifactStore
{
    public const PREFIX = 'monitoring-artifacts';

    public function __construct(private readonly string $disk = 'local') {}

    public function put(int $accountId, string $contents, string $mime = 'application/pdf'): array
    {
        $ref = Str::random(48);
        $sha256 = hash('sha256', $contents);

        Storage::disk($this->disk)->put(self::PREFIX."/{$ref}", $contents);

        MonitoringArtifact::query()->withoutGlobalScopes()->create([
            'account_id' => $accountId,
            'ref' => $ref,
            'sha256' => $sha256,
            'mime' => $mime,
            'size' => strlen($contents),
        ]);

        return ['ref' => $ref, 'sha256' => $sha256, 'size' => strlen($contents)];
    }

    public function get(int $accountId, string $ref): ?string
    {
        $record = MonitoringArtifact::query()->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('ref', $ref)
            ->first();

        if ($record === null) {
            return null;
        }

        $contents = Storage::disk($this->disk)->get(self::PREFIX."/{$ref}");

        return is_string($contents) ? $contents : null;
    }

    public function meta(int $accountId, string $ref): ?array
    {
        $record = MonitoringArtifact::query()->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('ref', $ref)
            ->first();

        if ($record === null) {
            return null;
        }

        return ['ref' => $record->ref, 'sha256' => $record->sha256, 'mime' => $record->mime, 'size' => $record->size];
    }

    public function delete(int $accountId, string $ref): void
    {
        MonitoringArtifact::query()->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('ref', $ref)
            ->delete();

        Storage::disk($this->disk)->delete(self::PREFIX."/{$ref}");
    }
}
