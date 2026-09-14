<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringSnapshot;
use App\Services\QueryQuotaService;
use App\Services\SnapshotService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringReadController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly QueryQuotaService $quota,
        private readonly SnapshotService $snapshots,
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

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        $accountId = $this->effectiveAccountId($request);
        $account = \App\Models\Account::query()->findOrFail($accountId);

        return response()->json([
            'enrollments' => [
                'active' => $this->countEnrollments($accountId, MonitoringEnrollment::ACTIVE),
                'paused' => $this->countEnrollments($accountId, MonitoringEnrollment::PAUSED),
                'ended' => $this->countEnrollments($accountId, MonitoringEnrollment::ENDED),
            ],
            'open_alerts' => MonitoringAlert::query()->withoutGlobalScopes()
                ->where('account_id', $accountId)
                ->where('status', MonitoringAlert::OPEN)
                ->count(),
            'quota' => $this->quota->balance($account),
        ]);
    }

    public function snapshots(Request $request, int $clientId): JsonResponse
    {
        $client = $this->clientForAccount($request, $clientId);
        $this->authorize('view', $client);

        // Apenas campos normalizados + metadados: sem payload bruto.
        $snapshots = MonitoringSnapshot::query()->withoutGlobalScopes()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->orderByDesc('checked_at')
            ->get(['id', 'family', 'normalized', 'version', 'completeness', 'checked_at']);

        return response()->json(['data' => $snapshots]);
    }

    public function changes(Request $request, int $clientId): JsonResponse
    {
        $client = $this->clientForAccount($request, $clientId);
        $this->authorize('view', $client);

        $changes = MonitoringChange::query()->withoutGlobalScopes()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $changes->items(),
            'meta' => ['current_page' => $changes->currentPage(), 'total' => $changes->total()],
        ]);
    }

    public function alerts(Request $request, int $clientId): JsonResponse
    {
        $client = $this->clientForAccount($request, $clientId);
        $this->authorize('view', $client);

        $alerts = MonitoringAlert::query()->withoutGlobalScopes()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $alerts->items(),
            'meta' => ['current_page' => $alerts->currentPage(), 'total' => $alerts->total()],
        ]);
    }

    public function acknowledge(Request $request, int $clientId, int $alertId): JsonResponse
    {
        $client = $this->clientForAccount($request, $clientId);
        $this->authorize('view', $client);

        $alert = MonitoringAlert::query()->withoutGlobalScopes()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->whereKey($alertId)
            ->firstOrFail();

        $this->authorize('acknowledge', $alert);

        return response()->json($this->snapshots->acknowledge($alert, $request->user()));
    }

    /**
     * CND lida do snapshot vigente de Situação Fiscal.
     * Nunca dispara consulta: leitura pura de banco.
     */
    public function cnd(Request $request, int $clientId): JsonResponse
    {
        $client = $this->clientForAccount($request, $clientId);
        $this->authorize('view', $client);

        $snapshot = MonitoringSnapshot::query()->withoutGlobalScopes()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->where('family', 'sitfis')
            ->orderByDesc('version')
            ->first();

        if ($snapshot === null) {
            return response()->json([
                'available' => false,
                'reason' => 'Sem snapshot de Situação Fiscal para este Client.',
            ]);
        }

        return response()->json([
            'available' => true,
            'situacao_fiscal' => $snapshot->normalized['situacao_fiscal'] ?? null,
            'cnd_disponivel' => $snapshot->normalized['cnd_disponivel'] ?? null,
            'checked_at' => $snapshot->checked_at,
        ]);
    }

    private function countEnrollments(int $accountId, string $status): int
    {
        return MonitoringEnrollment::query()->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('status', $status)
            ->count();
    }
}
