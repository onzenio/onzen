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
            // O grupo api abre sessão stateful via EnsureFrontendRequestsAreStateful,
            // então a sessão da requisição é a fonte única (sem fallback global).
            $targetId = $request->hasSession()
                ? $request->session()->get('switch_account_id')
                : null;

            if (is_numeric($targetId) && Account::query()->whereKey($targetId)->exists()) {
                $effective = (int) $targetId;
            }
        }

        CurrentAccount::set($effective);

        return $next($request);
    }
}
