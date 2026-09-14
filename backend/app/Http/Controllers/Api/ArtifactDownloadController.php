<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ArtifactStore;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactDownloadController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ArtifactStore $artifacts,
        private readonly AuditService $audit,
    ) {}

    private function effectiveAccountId(Request $request): int
    {
        return CurrentAccount::get() ?? $request->user()->account_id;
    }

    public function url(Request $request, string $ref): JsonResponse
    {
        $accountId = $this->effectiveAccountId($request);

        if ($this->artifacts->meta($accountId, $ref) === null) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        return response()->json([
            'url' => URL::temporarySignedRoute(
                'monitoring.artifacts.download',
                now()->addMinutes(15),
                ['ref' => $ref],
            ),
            'expires_in_minutes' => 15,
        ]);
    }

    public function download(Request $request, string $ref): StreamedResponse|JsonResponse
    {
        $accountId = $this->effectiveAccountId($request);
        $meta = $this->artifacts->meta($accountId, $ref);

        if ($meta === null) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }

        $contents = $this->artifacts->get($accountId, $ref);

        if ($contents === null) {
            return response()->json(['message' => 'Armazenamento de artefatos indisponível.'], 503);
        }

        $this->audit->record($request->user(), $accountId, $accountId, 'monitoring_artifact.downloaded', [
            'ref' => $ref,
            'sha256' => $meta['sha256'],
        ]);

        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            "artefato-{$ref}.pdf",
            ['Content-Type' => $meta['mime'] ?? 'application/pdf'],
        );
    }
}
