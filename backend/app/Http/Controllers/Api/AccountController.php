<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AccountProvisioningService;
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
            'invitation' => $invitation,
        ], 201);
    }
}
