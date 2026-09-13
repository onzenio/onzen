<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SerproBlockedException;
use App\Http\Controllers\Controller;
use App\Jobs\ExecuteSerproJob;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\User;
use App\Services\Monitoring\MonitoringScheduler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
