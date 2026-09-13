<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? auth()->user();

        $effective = $user?->account_id;

        if ($user?->isSuperAdmin() === true) {
            // Lê da sessão da requisição quando houver (grupo web / api stateful);
            // cai para o gerenciador de sessão para cobrir testes e api sem StartSession.
            $targetId = $request->hasSession()
                ? $request->session()->get('switch_account_id')
                : session()->get('switch_account_id');

            if (is_numeric($targetId) && Account::query()->whereKey($targetId)->exists()) {
                $effective = (int) $targetId;
            }
        }

        CurrentAccount::set($effective);

        return $next($request);
    }
}
