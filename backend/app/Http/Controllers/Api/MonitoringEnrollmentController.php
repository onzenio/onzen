<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MonitoringEnrollment;
use App\Services\EnrollmentService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringEnrollmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        $accountId = CurrentAccount::get() ?? $request->user()->account_id;
        $account = \App\Models\Account::query()->findOrFail($accountId);

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
        $account = \App\Models\Account::query()->findOrFail($accountId);

        // 404 cross-account antes de qualquer validação de negócio.
        $client = Client::query()->whereKey($data['client_id'])->firstOrFail();
        $this->authorize('view', $client);

        $enrollment = $this->enrollments->create($account, $data['client_id'], $data['definition_code'], $request->user());

        return response()->json($enrollment, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $enrollment = MonitoringEnrollment::query()->firstWhere('id', $id);

        if ($enrollment === null) {
            abort(404);
        }

        $this->authorize('view', $enrollment);

        return response()->json($enrollment->load('client'));
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $enrollment = MonitoringEnrollment::query()->firstWhere('id', $id);

        if ($enrollment === null) {
            abort(404);
        }

        $this->authorize('update', $enrollment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $enrollment->pause($data['reason']);

        return response()->json($enrollment->refresh());
    }

    public function resume(Request $request, int $id): JsonResponse
    {
        $enrollment = MonitoringEnrollment::query()->firstWhere('id', $id);

        if ($enrollment === null) {
            abort(404);
        }

        $this->authorize('update', $enrollment);
        $enrollment->resume();

        return response()->json($enrollment->refresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $enrollment = MonitoringEnrollment::query()->firstWhere('id', $id);

        if ($enrollment === null) {
            abort(404);
        }

        $this->authorize('delete', $enrollment);
        $enrollment->end();

        return response()->json($enrollment->refresh());
    }
}
