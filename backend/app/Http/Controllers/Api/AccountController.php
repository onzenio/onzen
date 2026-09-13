<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AccountProvisioningService;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::query()->with('plan')->paginate();

        return response()->json($accounts);
    }

    public function store(Request $request, AccountProvisioningService $provisioning): JsonResponse
    {
        $this->authorize('create', Account::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:255'],
        ]);

        $adminName = $data['admin_name'] ?? strstr($data['admin_email'], '@', true);

        ['account' => $account, 'invitation' => $invitation] = $provisioning->provision(
            $data['name'],
            $data['admin_email'],
            $adminName,
            $request->user(),
        );

        return response()->json([
            'account' => $account->load('plan'),
            'invitation' => $invitation->makeHidden('token'),
        ], 201);
    }

    public function updatePlan(Request $request, Account $account, AuditService $audit): JsonResponse
    {
        $this->authorize('update', Account::class);

        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $previousPlanId = $account->plan_id;

        $account->update(['plan_id' => $data['plan_id']]);

        $audit->record(
            $request->user(),
            CurrentAccount::get() ?? $request->user()->account_id,
            $account->id,
            'plan.changed',
            ['previous_plan_id' => $previousPlanId, 'plan_id' => $account->plan_id],
        );

        return response()->json($account->refresh()->load('plan'));
    }
}
