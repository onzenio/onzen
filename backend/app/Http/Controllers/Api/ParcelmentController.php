<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ParcelmentOrder;
use App\Services\ArtifactStore;
use App\Services\AuditService;
use App\Services\ParcelmentService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParcelmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ParcelmentService $parcelments,
        private readonly ArtifactStore $artifacts,
        private readonly AuditService $audit,
    ) {}

    private function effectiveAccountId(Request $request): int
    {
        return CurrentAccount::get() ?? $request->user()->account_id;
    }

    private function clientForAccount(Request $request, int $clientId): Client
    {
        return Client::query()->withoutGlobalScopes()
            ->where('account_id', $this->effectiveAccountId($request))
            ->whereKey($clientId)
            ->firstOrFail();
    }

    public function index(Request $request, int $clientId, string $modality): JsonResponse
    {
        $client = $this->clientForAccount($request, $clientId);
        $this->authorize('view', $client);

        $request->validate(['modality' => ['string']]);

        try {
            $orders = $this->parcelments->list($client, $modality);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $orders->map(fn (ParcelmentOrder $o) => [
                'id' => $o->id,
                'modality_code' => $o->modality_code,
                'order_number' => $o->order_number,
                'status' => $o->status,
                'total_value' => $o->total_value,
                'installments_count' => $o->installments->count(),
                'payments_count' => $o->payments->count(),
                'has_guia' => $o->guia_ref !== null,
            ]),
        ]);
    }

    public function show(Request $request, int $clientId, int $orderId): JsonResponse
    {
        $order = $this->orderForAccount($request, $clientId, $orderId);

        return response()->json([
            'id' => $order->id,
            'modality_code' => $order->modality_code,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'total_value' => $order->total_value,
            'has_guia' => $order->guia_ref !== null,
            'installments' => $order->installments,
            'payments' => $order->payments,
        ]);
    }

    /**
     * Download da guia JÁ gerada. Nunca emite: sem guia, 404 explícito.
     */
    public function guia(Request $request, int $clientId, int $orderId): StreamedResponse|JsonResponse
    {
        $order = $this->orderForAccount($request, $clientId, $orderId);

        if ($order->guia_ref === null) {
            return response()->json([
                'message' => 'Guia ainda não gerada para este pedido. Nenhuma emissão foi realizada.',
            ], 404);
        }

        $contents = $this->artifacts->get($order->account_id, $order->guia_ref);

        if ($contents === null) {
            return response()->json(['message' => 'Artefato da guia indisponível.'], 503);
        }

        $this->audit->record($request->user(), $order->account_id, $order->account_id, 'parcelment_guia.downloaded', [
            'order_id' => $order->id,
            'modality' => $order->modality_code,
        ]);

        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            "guia-{$order->order_number}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function orderForAccount(Request $request, int $clientId, int $orderId): ParcelmentOrder
    {
        $client = $this->clientForAccount($request, $clientId);
        $this->authorize('view', $client);

        return ParcelmentOrder::query()->withoutGlobalScopes()
            ->with(['installments', 'payments'])
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->whereKey($orderId)
            ->firstOrFail();
    }
}
