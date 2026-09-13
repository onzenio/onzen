<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invitation;
use App\Services\InvitationService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvitationController extends Controller
{
    use AuthorizesRequests;

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Invitation::class);

        return response()->json(Invitation::query()->latest()->paginate());
    }

    public function store(Request $request, InvitationService $invitations): JsonResponse
    {
        $this->authorize('create', Invitation::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $account = Account::query()->findOrFail(CurrentAccount::get() ?? $user->account_id);

        $invitation = $invitations->invite($account, $user, $data);

        return response()->json($invitation, 201);
    }

    public function accept(Request $request, string $token, InvitationService $invitations): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = $invitations->accept($token, $data['password']);

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
        ], 201);
    }

    public function destroy(string $id, InvitationService $invitations): JsonResponse
    {
        // Busca manual escopada: a substituição implícita de modelos roda
        // antes do ResolveAccount, então o global scope ainda não vale no
        // binding — aqui o CurrentAccount já está resolvido e outra Account
        // resulta em 404 (sem leak), nunca 403.
        $invitation = Invitation::query()->whereKey($id)->firstOrFail();

        $this->authorize('delete', $invitation);

        $invitations->revoke($invitation);

        return response()->json(null, 204);
    }
}
