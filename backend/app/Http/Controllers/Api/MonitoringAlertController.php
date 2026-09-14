<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringEnrollment;
use App\Models\User;
use App\Services\Monitoring\MonitoringAlertService;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Alertas de monitoramento (Task 25): listagem paginada com filtro de status
 * e reconhecimento idempotente.
 *
 * Isolamento por Account no route binding (cross-account responde 404);
 * o reconhecimento segue a policy de escrita (`admin`/`operator`; `user`
 * recebe 403) e é auditado pelo serviço da Task 23.
 */
class MonitoringAlertController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MonitoringAlertService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringAlert::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                MonitoringAlert::STATUS_PENDING,
                MonitoringAlert::STATUS_ACKNOWLEDGED,
            ])],
            'enrollment_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $enrollmentId = $filters['enrollment_id'] ?? null;
        $clientId = $filters['client_id'] ?? null;

        if ($enrollmentId !== null) {
            MonitoringEnrollment::query()->findOrFail($enrollmentId);
        }

        if ($clientId !== null) {
            Client::query()->findOrFail($clientId);
        }

        $page = MonitoringAlert::query()
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when($enrollmentId !== null, fn ($query) => $query->where('enrollment_id', $enrollmentId))
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn (MonitoringAlert $alert): array => MonitoringReadPayload::alert($alert));

        return response()->json($page);
    }

    public function acknowledge(Request $request, MonitoringAlert $alert): JsonResponse
    {
        $this->authorize('acknowledge', $alert);

        /** @var User $actor */
        $actor = $request->user();

        $alert = $this->service->acknowledge($alert, $actor);

        return response()->json(['data' => MonitoringReadPayload::alert($alert)]);
    }
}
