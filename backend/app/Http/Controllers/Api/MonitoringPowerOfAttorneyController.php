<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SerproBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\VerifyClientPowerOfAttorneyRequest;
use App\Integrations\Serpro\ConsultOperationResolver;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\User;
use App\Services\Monitoring\ProcurationGate;
use App\Services\Monitoring\ProcurationStatusService;
use App\Services\Monitoring\ProcurationVerifier;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Procurações da carteira (Task 20): leitura do estado por código do Client
 * (registro local + cache), verificação sob demanda e divergências da Account.
 *
 * Leitura isolada por Account (404 cross-account via binding). A verificação
 * exige `admin`/`operator`; a listagem de divergências é leitura da carteira.
 */
class MonitoringPowerOfAttorneyController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ProcurationGate $gate,
        private readonly ProcurationStatusService $status,
        private readonly ConsultOperationResolver $operations,
    ) {}

    public function index(Request $request, Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json(['data' => $this->status->forClient($client)]);
    }

    public function verify(VerifyClientPowerOfAttorneyRequest $request, Client $client): JsonResponse
    {
        /** @var string|null $definitionId */
        $definitionId = $request->validated()['definition_id'] ?? null;
        $definitions = $this->definitions($client, $definitionId);

        if ($definitions->isEmpty()) {
            return response()->json([
                'message' => 'Verificação de procuração recusada.',
                'error' => ProcurationVerifier::REASON_UNRESOLVED,
            ], 409);
        }

        $results = [];

        try {
            foreach ($definitions as $definition) {
                $result = $this->gate->verifyForClient(
                    $client,
                    $definition,
                    $this->operationFor($definition),
                );

                $results[] = [
                    'definition_id' => (string) $definition->getKey(),
                    'verified' => (bool) $result['verified'],
                    'reason' => $result['reason'],
                    'required_groups' => $result['required_groups'],
                    'missing_groups' => $result['missing_groups'],
                    'from_cache' => (bool) $result['from_cache'],
                ];
            }
        } catch (SerproBlockedException $exception) {
            return response()->json([
                'message' => 'Verificação de procuração indisponível.',
                'error' => $exception->getMessage(),
                'data' => ['results' => $results],
            ], 409);
        }

        return response()->json(['data' => ['results' => $results]]);
    }

    public function divergences(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        /** @var User $actor */
        $actor = $request->user();

        $accountId = (int) (CurrentAccount::get() ?? $actor->account_id);

        return response()->json(['data' => $this->status->divergences($accountId)]);
    }

    /**
     * Definitions to verify: the requested one, or every definition currently
     * associated with the Client (active or paused).
     *
     * @return Collection<int, MonitoringDefinition>
     */
    private function definitions(Client $client, ?string $definitionId): Collection
    {
        if (is_string($definitionId) && trim($definitionId) !== '') {
            $definition = MonitoringDefinition::query()->find($definitionId);

            return $definition === null ? collect() : collect([$definition]);
        }

        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->whereIn('status', [MonitoringEnrollment::STATUS_ACTIVE, MonitoringEnrollment::STATUS_PAUSED])
            ->with('definition')
            ->get()
            ->pluck('definition')
            ->filter()
            ->unique(fn (MonitoringDefinition $definition): string => (string) $definition->getKey())
            ->values();
    }

    private function operationFor(MonitoringDefinition $definition): ?string
    {
        try {
            return $this->operations->resolve($definition);
        } catch (\Throwable) {
            // The definition may have no resolvable consult operation; the
            // verifier still receives the definition and fails closed on the
            // requirement mapping it owns.
            return null;
        }
    }
}
