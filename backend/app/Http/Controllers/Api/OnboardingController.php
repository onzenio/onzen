<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OnboardingController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'available' => Account::query()->count() === 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (Account::query()->exists()) {
            return response()->json([
                'message' => 'O cadastro inicial já foi concluído. Solicite um convite para entrar.',
            ], 409);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'account_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            [$account, $user] = DB::transaction(function () use ($data) {
                // Trava a tabela contra corrida: segunda conclusão concorrente
                // bloqueia aqui e depois viola a constraint parcial (profile=A).
                if (DB::getDriverName() !== 'sqlite') {
                    Account::query()->where('profile', AccountProfile::A)->lockForUpdate()->exists();
                }
                $account = Account::query()->create([
                    'name' => $data['account_name'],
                    'profile' => AccountProfile::A,
                ]);

                $user = User::query()->create([
                    'account_id' => $account->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => UserRole::SuperAdmin,
                ]);

                return [$account, $user];
            });
        } catch (QueryException) {
            // Corrida entre duas conclusões: a perdedora vira 409 sem autenticar.
            return response()->json([
                'message' => 'O cadastro inicial já foi concluído. Solicite um convite para entrar.',
            ], 409);
        }

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'profile' => $account->profile->value,
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
        ], 201);
    }
}
