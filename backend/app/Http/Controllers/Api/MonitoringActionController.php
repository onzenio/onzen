<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SerproBlockedException;
use App\Http\Controllers\Controller;
use App\Integrations\Serpro\ConsultCatalog;
use App\Jobs\ExecuteSerproActionJob;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Models\ParcelmentInstallment;
use App\Models\SerproServiceRequest;
use App\Services\Monitoring\SerproActionExecutor;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ações fiscais explícitas de emissão de DAS (Task 27).
 *
 * Três portas estreitas: PGDAS-D (`GERARDAS12`) a partir de uma inscrição
 * PGDAS-D ativa, parcelamento (`GERARDAS*` da modalidade catalogada) a partir
 * de uma parcela, e a leitura da ação. Todas exigem admin/operator, confirmação
 * explícita e chave de idempotência; nenhuma chamada externa acontece na
 * requisição (o executor reserva a intenção e enfileira
 * {@see ExecuteSerproActionJob}). O binding de rota resolve pelo
 * escopo da Account, então recurso de outra carteira responde 404
 * indistinguível; as recusas factuais respondem 422 (409 para conflito de
 * chave) com o motivo explícito.
 */
class MonitoringActionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SerproActionExecutor $executor) {}

    public function generateDasForEnrollment(Request $request, MonitoringEnrollment $enrollment): JsonResponse
    {
        $this->authorize('create', SerproServiceRequest::class);

        if ($enrollment->definition_id !== 'pgdas-declaracoes') {
            return response()->json([
                'message' => 'Emissão de DAS disponível apenas para inscrições PGDAS-D ativas.',
                'error' => 'emission_definition_mismatch',
            ], 422);
        }

        $data = $request->validate([
            'periodo_apuracao' => ['required', 'string', 'regex:/^(\d{4})(0[1-9]|1[0-2])$/'],
            'data_consolidacao' => ['nullable', 'date'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'confirmed' => ['accepted'],
        ]);

        $client = $enrollment->client()->first();

        if ($client === null) {
            return response()->json(['message' => 'Client não encontrado.', 'error' => 'client_missing'], 422);
        }

        try {
            $action = $this->executor->request(
                $client,
                'GERARDAS12',
                (string) $data['idempotency_key'],
                true,
                [
                    'periodo_apuracao' => (string) $data['periodo_apuracao'],
                    'data_consolidacao' => (string) ($data['data_consolidacao'] ?? now()->toDateString()),
                ],
                $enrollment,
                null,
                $request->user(),
            );
        } catch (SerproBlockedException $exception) {
            return $this->refusal($exception);
        }

        return $this->accepted($action);
    }

    public function generateDasForInstallment(Request $request, ParcelmentInstallment $installment): JsonResponse
    {
        $this->authorize('create', SerproServiceRequest::class);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'confirmed' => ['accepted'],
        ]);

        $installment->loadMissing('order');

        $operation = ConsultCatalog::gerardasForModality((string) ($installment->order?->modality ?? ''));

        if ($operation === null) {
            return response()->json([
                'message' => 'Modalidade de parcelamento sem emissão de DAS catalogada.',
                'error' => 'parcelment_modality_unavailable',
            ], 422);
        }

        $client = Client::query()->whereKey($installment->client_id)->first();

        if ($client === null) {
            return response()->json(['message' => 'Client não encontrado.', 'error' => 'client_missing'], 422);
        }

        try {
            $action = $this->executor->request(
                $client,
                $operation,
                (string) $data['idempotency_key'],
                true,
                [],
                null,
                $installment,
                $request->user(),
            );
        } catch (SerproBlockedException $exception) {
            return $this->refusal($exception);
        }

        return $this->accepted($action);
    }

    public function show(SerproServiceRequest $action): JsonResponse
    {
        $this->authorize('view', $action);

        return response()->json(['data' => MonitoringReadPayload::serviceRequest($action)]);
    }

    private function accepted(SerproServiceRequest $action): JsonResponse
    {
        return response()->json(
            ['data' => MonitoringReadPayload::serviceRequest($action)],
            $action->wasRecentlyCreated ? 202 : 200,
        );
    }

    private function refusal(SerproBlockedException $exception): JsonResponse
    {
        $conflict = $exception->getMessage() === SerproActionExecutor::ERROR_KEY_CONFLICT;

        return response()->json([
            'message' => $conflict
                ? 'A chave de idempotência já está vinculada a outra emissão.'
                : 'Emissão de DAS recusada.',
            'error' => $exception->getMessage(),
        ], $conflict ? 409 : 422);
    }
}
