<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MonitoringAlert;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Models\User;
use App\Services\Monitoring\QueryQuotaService;
use App\Support\CurrentAccount;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel da carteira (Task 25): contagens factuais por estado, alertas
 * pendentes, última execução e consumo de quota do Plan da Account efetiva.
 *
 * Somente leitura e somente contagens/metadados — nenhum snapshot, payload
 * fiscal ou segredo é exposto. A quota vem de {@see QueryQuotaService::usage}
 * para a Account efetiva, nunca de cache.
 */
class MonitoringDashboardController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, QueryQuotaService $quota): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        /** @var User $actor */
        $actor = $request->user();
        $accountId = (int) (CurrentAccount::get() ?? $actor->account_id);

        $account = Account::query()->find($accountId);

        if ($account === null) {
            abort(404);
        }

        $associations = MonitoringEnrollment::query()
            ->where('account_id', $accountId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $alerts = MonitoringAlert::query()
            ->where('account_id', $accountId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $lastRun = MonitoringRun::query()
            ->with('enrollment:id,client_id')
            ->where('account_id', $accountId)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => [
                'associations' => [
                    'active' => (int) $associations->get(MonitoringEnrollment::STATUS_ACTIVE, 0),
                    'paused' => (int) $associations->get(MonitoringEnrollment::STATUS_PAUSED, 0),
                    'ended' => (int) $associations->get(MonitoringEnrollment::STATUS_ENDED, 0),
                    'total' => (int) $associations->sum(),
                ],
                'alerts' => [
                    'pending' => (int) $alerts->get(MonitoringAlert::STATUS_PENDING, 0),
                    'acknowledged' => (int) $alerts->get(MonitoringAlert::STATUS_ACKNOWLEDGED, 0),
                    'total' => (int) $alerts->sum(),
                ],
                'last_run' => $lastRun === null ? null : [
                    ...MonitoringReadPayload::run($lastRun),
                    'client_id' => $lastRun->enrollment?->client_id,
                ],
                'quota' => $quota->usage($account),
            ],
        ]);
    }
}
