<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoringChange;
use App\Models\MonitoringEnrollment;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mudanças detectadas de uma Associação (Task 25).
 *
 * Paginado e isolado pela Account da associação (cross-account responde 404).
 * O `data` (`before`/`after`) só é exposto quando o snapshot que originou a
 * mudança foi normalizado; referências internas de armazenamento e conteúdo
 * bruto são removidos mesmo nesse caso.
 */
class MonitoringChangeController extends Controller
{
    use AuthorizesRequests;

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

        $page = MonitoringChange::query()
            ->with('snapshot:id,normalized')
            ->where('enrollment_id', $enrollment->getKey())
            ->when($operationCode !== null && $operationCode !== '', fn ($query) => $query->where('operation_code', $operationCode))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn (MonitoringChange $change): array => MonitoringReadPayload::change($change));

        return response()->json($page);
    }
}
