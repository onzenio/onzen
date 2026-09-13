<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ArtifactStore;
use App\Exceptions\ArtifactStorageUnavailableException;
use App\Http\Controllers\Controller;
use App\Integrations\Serpro\ConsultCatalog;
use App\Models\Client;
use App\Models\MonitoringArtifact;
use App\Models\ParcelmentInstallment;
use App\Models\ParcelmentOrder;
use App\Models\ParcelmentPayment;
use App\Services\AuditService;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parcelamentos (Task 26): consulta normalizada de pedidos, detalhe com
 * parcelas/pagamentos e download de guia já existente.
 *
 * Todas as rotas são isoladas por Account: o binding de rota usa o escopo
 * global de `BelongsToAccount` resolvido pelo `ResolveAccount`, então um
 * recurso de outra Account responde 404 indistinguível. O download da guia
 * entrega somente artefato já existente — nunca emite —, é auditado e segue
 * a matriz de Role do artefato (admin/operator).
 */
class ParcelmentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'modalidade' => ['nullable', 'string', Rule::in(ConsultCatalog::PARCELMENT_MODALITIES)],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $clientId = $filters['client_id'] ?? null;

        if ($clientId !== null) {
            // Cross-account filters answer an indistinguishable 404 instead
            // of silently returning an empty page.
            Client::query()->findOrFail($clientId);
        }

        $page = ParcelmentOrder::query()
            ->with(['client', 'installments'])
            ->when(isset($filters['modalidade']), fn ($query) => $query->where('modality', $filters['modalidade']))
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn (ParcelmentOrder $order): array => MonitoringReadPayload::parcelmentOrder($order));

        return response()->json($page);
    }

    public function show(ParcelmentOrder $parcelment): JsonResponse
    {
        return response()->json([
            'data' => MonitoringReadPayload::parcelmentOrderDetail(
                $parcelment->load(['client', 'installments.payments']),
            ),
        ]);
    }

    public function installments(Request $request, ParcelmentOrder $parcelment): JsonResponse
    {
        $perPage = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])['per_page'] ?? 50;

        $page = $parcelment->installments()
            ->orderBy('number')
            ->paginate((int) $perPage)
            ->through(fn (ParcelmentInstallment $installment): array => MonitoringReadPayload::parcelmentInstallment($installment));

        return response()->json($page);
    }

    public function payments(Request $request, ParcelmentInstallment $installment): JsonResponse
    {
        $perPage = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])['per_page'] ?? 50;

        $page = $installment->payments()
            ->orderByDesc('id')
            ->paginate((int) $perPage)
            ->through(fn (ParcelmentPayment $payment): array => MonitoringReadPayload::parcelmentPayment($payment));

        return response()->json($page);
    }

    /**
     * Download auditado da guia (DAS) já existente de uma parcela.
     *
     * Fail-closed: parcela sem guia, referência sem artefato e arquivo físico
     * ausente respondem o mesmo 404 factual — nenhuma emissão é disparada. O
     * armazenamento indisponível responde 503 retryable sem vazar caminho.
     */
    public function downloadGuide(
        Request $request,
        ParcelmentInstallment $installment,
        ArtifactStore $store,
        AuditService $audit,
    ): Response {
        $ref = trim((string) $installment->guide_ref);

        if ($ref === '') {
            return $this->guideNotFound();
        }

        $artifact = MonitoringArtifact::query()
            ->where('ref', $ref)
            ->where('account_id', $installment->account_id)
            ->first();

        if ($artifact === null) {
            return $this->guideNotFound();
        }

        $this->authorize('download', $artifact);

        try {
            $contents = $store->get($ref);
        } catch (ArtifactStorageUnavailableException) {
            $audit->record($request->user(), 'monitoring.parcelment.guide.download_failed', [
                'ref' => $ref,
                'installment_id' => $installment->getKey(),
                'reason' => 'storage_unavailable',
            ], $artifact->account);

            Log::warning('monitoring.parcelment_guide_storage_unavailable', [
                'ref' => $ref,
                'account_id' => $artifact->account_id,
            ]);

            return response()->json([
                'message' => 'O armazenamento de artefatos está temporariamente indisponível. Tente novamente.',
                'code' => 'ARTIFACT_STORAGE_UNAVAILABLE',
                'retryable' => true,
            ], 503, ['Retry-After' => '15']);
        }

        if ($contents === null) {
            return $this->guideNotFound();
        }

        $audit->record($request->user(), 'monitoring.parcelment.guide.downloaded', [
            'ref' => $ref,
            'installment_id' => $installment->getKey(),
            'order_id' => $installment->order_id,
            'hash_sha256' => $artifact->hash_sha256,
        ], $artifact->account);

        $filename = $this->guideFilename($artifact, $installment);

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

    private function guideNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Guia ainda não disponível para esta parcela.'], 404);
    }

    private function guideFilename(MonitoringArtifact $artifact, ParcelmentInstallment $installment): string
    {
        $name = $artifact->original_name;

        if (! is_string($name) || trim($name) === '') {
            $name = 'guia-parcela-'.$installment->getKey().'.pdf';
        }

        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', basename(str_replace('\\', '/', $name))) ?? '';
        $sanitized = trim($sanitized, '.-');

        return $sanitized !== '' ? $sanitized : 'guia.pdf';
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
