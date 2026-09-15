<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SwitchController extends Controller
{
    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        $target = Account::query()->findOrFail($data['account_id']);

        if ($request->hasSession()) {
            $request->session()->put('switch_account_id', $target->id);
            // Troca de tenant efetivo regenera o ID de sessão DESTRUINDO o
            // antigo (anti-fixação): regenerate() sem args manteria o ID
            // anterior válido com o switch ativo.
            $request->session()->regenerate(true);
        }

        $audit->record($user, $user->account_id, $target->id, 'account.switch.enter');

        return response()->json([
            'account' => [
                'id' => $target->id,
                'name' => $target->name,
                'profile' => $target->profile->value,
            ],
            // Mesma regra do /api/me: sem banner quando o alvo é a própria.
            'acting_as' => $target->id === $user->account_id ? null : ['account_id' => $target->id],
        ]);
    }

    public function destroy(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            abort(403);
        }

        $previous = $request->hasSession()
            ? $request->session()->get('switch_account_id')
            : null;

        if ($request->hasSession()) {
            $request->session()->forget('switch_account_id');
            $request->session()->regenerate(true);
        }

        $home = Account::query()->findOrFail($user->account_id);

        $audit->record(
            $user,
            $user->account_id,
            is_numeric($previous) ? (int) $previous : null,
            'account.switch.exit',
        );

        return response()->json([
            'account' => [
                'id' => $home->id,
                'name' => $home->name,
                'profile' => $home->profile->value,
            ],
            'acting_as' => null,
        ]);
    }
}
