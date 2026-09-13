<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ArtifactStore;
use App\Exceptions\ArtifactStorageUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\MonitoringArtifact;
use App\Models\User;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed, account-scoped and audited artifact download.
 *
 * The opaque `ref` never encodes the Account: an unknown ref and a ref from
 * another Account answer the same 404, so existence is not distinguishable.
 */
class MonitoringArtifactDownloadController extends Controller
{
    public function __invoke(Request $request, string $ref, ArtifactStore $store, AuditService $audit): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $this->notFound();
        }

        $accountId = CurrentAccount::get() ?? $user->account_id;

        $artifact = MonitoringArtifact::query()
            ->where('ref', $ref)
            ->where('account_id', $accountId)
            ->first();

        if ($artifact === null) {
            return $this->notFound();
        }

        try {
            $contents = $store->get($ref);
        } catch (ArtifactStorageUnavailableException $exception) {
            $audit->record($user, 'monitoring.artifact.download_failed', [
                'ref' => $artifact->ref,
                'reason' => 'storage_unavailable',
            ], $artifact->account);

            Log::warning('monitoring.artifact_storage_unavailable', [
                'ref' => $artifact->ref,
                'account_id' => $artifact->account_id,
            ]);

            return response()->json([
                'message' => 'O armazenamento de artefatos está temporariamente indisponível. Tente novamente.',
                'code' => 'ARTIFACT_STORAGE_UNAVAILABLE',
                'retryable' => true,
            ], 503, ['Retry-After' => '15']);
        }

        if ($contents === null) {
            return $this->notFound();
        }

        $audit->record($user, 'monitoring.artifact.downloaded', [
            'ref' => $artifact->ref,
            'kind' => $artifact->kind,
            'hash_sha256' => $artifact->hash_sha256,
        ], $artifact->account);

        $filename = $this->filename($artifact, $ref);

        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            $filename,
            [
                'Content-Type' => $this->contentType($artifact->kind, $filename),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Artefato não encontrado.'], 404);
    }

    private function filename(MonitoringArtifact $artifact, string $ref): string
    {
        $name = $artifact->original_name;

        if (! is_string($name) || trim($name) === '') {
            $extension = match ($artifact->kind) {
                'pdf' => 'pdf',
                'xml' => 'xml',
                default => 'bin',
            };
            $name = 'artifact-'.$ref.'.'.$extension;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', basename(str_replace('\\', '/', $name))) ?? '';
        $sanitized = trim($sanitized, '.-');

        return $sanitized !== '' ? $sanitized : 'artifact';
    }

    private function contentType(string $kind, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match (true) {
            $kind === 'pdf' || $extension === 'pdf' => 'application/pdf',
            $kind === 'xml' || $extension === 'xml' => 'application/xml; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }
}
