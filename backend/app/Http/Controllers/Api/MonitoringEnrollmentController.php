<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreMonitoringEnrollmentRequest;
use App\Http\Requests\Monitoring\UpdateMonitoringEnrollmentRequest;
use App\Models\MonitoringEnrollment;
use App\Models\User;
use App\Services\Monitoring\MonitoringEnrollmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Associações de Monitoramento da carteira: listagem paginada com busca por
 * Client, criação com elegibilidade fail-closed, leitura, mudança de
 * configuração e encerramento (que preserva o histórico).
 */
class MonitoringEnrollmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MonitoringEnrollmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                MonitoringEnrollment::STATUS_ACTIVE,
                MonitoringEnrollment::STATUS_PAUSED,
                MonitoringEnrollment::STATUS_ENDED,
            ])],
            'definition_id' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $page = $this->service->paginate($actor, $filters);
        $page->through(fn (MonitoringEnrollment $enrollment): array => $this->payload($enrollment));

        return response()->json($page);
    }

    public function store(StoreMonitoringEnrollmentRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $enrollment = $this->service->create($actor, $request->validated());

        return response()->json(['data' => $this->payload($enrollment)], 201);
    }

    public function show(MonitoringEnrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        return response()->json(['data' => $this->payload($enrollment->load(['client', 'definition']))]);
    }

    public function update(UpdateMonitoringEnrollmentRequest $request, MonitoringEnrollment $enrollment): JsonResponse
    {
        $enrollment = $this->service->updateConfiguration($enrollment, $request->validated()['configuration']);

        return response()->json(['data' => $this->payload($enrollment)]);
    }

    public function destroy(MonitoringEnrollment $enrollment): JsonResponse
    {
        $this->authorize('delete', $enrollment);

        return response()->json(['data' => $this->payload($this->service->end($enrollment))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MonitoringEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
            'pause_reason' => $enrollment->pause_reason,
            'version' => $enrollment->version,
            'configuration' => $enrollment->configuration,
            'last_change_at' => $enrollment->last_change_at?->toIso8601String(),
            'client' => $enrollment->client === null ? null : [
                'id' => $enrollment->client->id,
                'razao_social' => $enrollment->client->razao_social,
                'cnpj' => $enrollment->client->cnpj,
                'monitoring_enabled' => $enrollment->client->monitoring_enabled,
            ],
            'definition' => $enrollment->definition === null ? null : [
                'id' => $enrollment->definition->id,
                'name' => $enrollment->definition->name,
                'category' => $enrollment->definition->category,
                'version' => $enrollment->definition->version,
                'availability' => $enrollment->definition->availability,
                'is_active' => $enrollment->definition->is_active,
            ],
            'created_at' => $enrollment->created_at?->toIso8601String(),
            'updated_at' => $enrollment->updated_at?->toIso8601String(),
        ];
    }
}
