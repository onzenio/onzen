<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringSnapshot;
use App\Services\Monitoring\SnapshotProjector;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Histórico de snapshots de uma Associação (Task 25).
 *
 * Paginado, isolado pela Account da associação (cross-account responde 404
 * no route binding). Cada versão publicada expõe seus metadados factuais e
 * o `data` normalizado — nunca o payload bruto nem referências internas de
 * armazenamento. O `state` do topo deriva de {@see SnapshotProjector::stateFor}
 * (completude fail-closed) para a leitura corrente.
 */
class MonitoringSnapshotController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SnapshotProjector $projector) {}

    public function index(Request $request, MonitoringEnrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        $filters = $request->validate([
            'operation_code' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $operationCode = isset($filters['operation_code'])
            ? strtoupper(trim($filters['operation_code']))
            : null;

        $page = MonitoringSnapshot::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->when($operationCode !== null && $operationCode !== '', fn ($query) => $query->where('operation_code', $operationCode))
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn (MonitoringSnapshot $snapshot): array => MonitoringReadPayload::snapshot($snapshot));

        return response()->json([
            ...$page->toArray(),
            // Fail-closed current state; scoped to the filtered operation when
            // one is requested, otherwise to every operation of the enrollment.
            'state' => $this->projector->stateFor($enrollment, $operationCode),
        ]);
    }
}
