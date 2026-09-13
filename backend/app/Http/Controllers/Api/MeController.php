<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $account = Account::query()->with('plan')->findOrFail(
            CurrentAccount::get() ?? $user->account_id
        );

        $actingAs = null;

        if ($user->isSuperAdmin()
            && CurrentAccount::get() !== null
            && CurrentAccount::get() !== $user->account_id
        ) {
            $actingAs = ['account_id' => $account->id];
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'profile' => $account->profile->value,
                'plan' => $account->plan ? [
                    'id' => $account->plan->id,
                    'name' => $account->plan->name,
                    'price_cents' => $account->plan->price_cents,
                    'max_users' => $account->plan->max_users,
                    'max_clients' => $account->plan->max_clients,
                    'modules' => $account->plan->modules,
                    'monthly_query_volume' => $account->plan->monthly_query_volume,
                    'is_default' => $account->plan->is_default,
                ] : null,
            ],
            'acting_as' => $actingAs,
        ]);
    }
}
