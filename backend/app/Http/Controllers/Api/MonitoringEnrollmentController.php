<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Services\EnrollmentService;
use App\Services\MonitoringScheduler;
use App\Services\OutorgaSyncService;
use App\Services\QuotaExhaustedException;
use App\Support\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MonitoringEnrollmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly OutorgaSyncService $outorga,
    ) {}

    private function effectiveAccountId(Request $request): int
    {
        return CurrentAccount::get() ?? $request->user()->account_id;
    }

    private function findForAccount(Request $request, int $id): MonitoringEnrollment
    {
        // 404 indistinguível fora da Account efetiva.
        return MonitoringEnrollment::query()->withoutGlobalScopes()
            ->where('account_id', $this->effectiveAccountId($request))
            ->whereKey($id)
            ->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        $accountId = CurrentAccount::get() ?? $request->user()->account_id;
        $account = Account::query()->findOrFail($accountId);

        $page = $this->enrollments->search($account, $request->query('q'), 15);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MonitoringEnrollment::class);

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'definition_code' => ['required', 'string', 'max:60'],
        ]);

        $accountId = CurrentAccount::get() ?? $request->user()->account_id;
        $account = Account::query()->findOrFail($accountId);

        // 404 cross-account antes de qualquer validação de negócio.
        $client = Client::query()->whereKey($data['client_id'])->firstOrFail();
        $this->authorize('view', $client);

        $enrollment = $this->enrollments->create($account, $data['client_id'], $data['definition_code'], $request->user());

        return response()->json($enrollment, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $enrollment = $this->findForAccount($request, $id);
        $this->authorize('view', $enrollment);

        return response()->json($enrollment->load('client'));
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $enrollment = $this->findForAccount($request, $id);
        $this->authorize('update', $enrollment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $enrollment->pause($data['reason']);

        return response()->json($enrollment->refresh());
    }

    public function resume(Request $request, int $id): JsonResponse
    {
        $enrollment = $this->findForAccount($request, $id);
        $this->authorize('update', $enrollment);
        $enrollment->resume();

        return response()->json($enrollment->refresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $enrollment = $this->findForAccount($request, $id);
        $this->authorize('delete', $enrollment);
        $enrollment->end();

        return response()->json($enrollment->refresh());
    }

    public function divergences(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        return response()->json([
            'data' => $this->outorga->divergences($this->effectiveAccountId($request)),
        ]);
    }

    /**
     * Disparo manual de consulta: responde 202 sem tráfego na requisição.
     */
    public function trigger(Request $request, int $id): JsonResponse
    {
        $enrollment = $this->findForAccount($request, $id);
        $this->authorize('update', $enrollment);

        if ($enrollment->status !== MonitoringEnrollment::ACTIVE) {
            return response()->json(['message' => 'Associação inativa: disparo não permitido.'], 422);
        }

        $data = $request->validate([
            'idempotency_key' => ['sometimes', 'string', 'max:120'],
        ]);

        $account = Account::query()->findOrFail($enrollment->account_id);

        try {
            $run = app(MonitoringScheduler::class)->triggerManual(
                $account,
                $request->user(),
                $enrollment->definition_code,
                $enrollment->client_id,
                $data['idempotency_key'] ?? (string) Str::uuid(),
            );
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (QuotaExhaustedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['run_id' => $run->id, 'status' => $run->status], 202);
    }
}
