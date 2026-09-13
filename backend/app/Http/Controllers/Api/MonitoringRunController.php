<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitoringRunStatus;
use App\Exceptions\SerproBlockedException;
use App\Http\Controllers\Controller;
use App\Jobs\ExecuteSerproJob;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\User;
use App\Services\Monitoring\MonitoringScheduler;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Disparo de execuções (Task 18): manual por associação (202 com o
 * identificador da execução) e lote da carteira com contagens factuais.
 *
 * Nenhuma chamada externa acontece na requisição: o scheduler reserva a
 * quota e enfileira {@see ExecuteSerproJob} para a fila `serpro`.
 */
class MonitoringRunController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MonitoringScheduler $scheduler) {}

    /**
     * Listagem paginada das execuções da Account efetiva (Task 25).
     *
     * Somente metadados factuais: status, gatilho, operação, protocolo, ETA,
     * erro e timestamps. `parameters` são entradas de consulta não sensíveis
     * e permanecem no payload; chaves internas ou sensíveis são removidas.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        $filters = $request->validate([
            'enrollment_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_map(
                fn (MonitoringRunStatus $status): string => $status->value,
                MonitoringRunStatus::cases(),
            ))],
            'trigger' => ['nullable', Rule::in([
                MonitoringRun::TRIGGER_MANUAL,
                MonitoringRun::TRIGGER_AUTOMATIC,
            ])],
            'operation_code' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $enrollmentId = $filters['enrollment_id'] ?? null;

        if ($enrollmentId !== null) {
            // Cross-account filters answer an indistinguishable 404 instead
            // of silently returning an empty page.
            MonitoringEnrollment::query()->findOrFail($enrollmentId);
        }

        $operationCode = isset($filters['operation_code'])
            ? strtoupper(trim($filters['operation_code']))
            : null;

        $page = MonitoringRun::query()
            ->when($enrollmentId !== null, fn ($query) => $query->where('enrollment_id', $enrollmentId))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['trigger']), fn ($query) => $query->where('trigger', $filters['trigger']))
            ->when($operationCode !== null && $operationCode !== '', fn ($query) => $query->where('operation_code', $operationCode))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn (MonitoringRun $run): array => MonitoringReadPayload::run($run));

        return response()->json($page);
    }

    public function run(Request $request, MonitoringEnrollment $enrollment): JsonResponse
    {
        $this->authorize('run', $enrollment);

        try {
            $run = $this->scheduler->schedule($enrollment, MonitoringRun::TRIGGER_MANUAL);
        } catch (SerproBlockedException $exception) {
            return response()->json([
                'message' => 'Execução recusada.',
                'error' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $this->payload($run)], 202);
    }

    public function sync(Request $request): JsonResponse
    {
        $this->authorize('create', MonitoringEnrollment::class);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'min:1'],
            'definition_id' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->scheduler->sync($actor, $filters),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MonitoringRun $run): array
    {
        return [
            'id' => $run->id,
            'enrollment_id' => $run->enrollment_id,
            'trigger' => $run->trigger,
            'status' => $run->status->value,
            'fencing_token' => $run->fencing_token,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
