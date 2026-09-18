<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\PlanLimitService;
use App\Support\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nega leitura ou escrita de um Module ausente do Plan vigente da Account
 * efetiva, antes de qualquer consulta, criação ou alteração de dados.
 *
 * O monitoramento fiscal opera sobre a carteira, então usa o Module
 * equivalente `clients` (nenhum Module novo é criado aqui).
 */
class EnsurePlanModule
{
    public function __construct(private readonly PlanLimitService $limits) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $accountId = CurrentAccount::get() ?? $request->user()?->account_id;
        $account = is_numeric($accountId) ? Account::query()->find($accountId) : null;

        if ($account === null) {
            return response()->json([
                'message' => 'Conta da sessão não localizada.',
                'code' => 'ACCOUNT_NOT_FOUND',
            ], 403);
        }

        $denied = $this->limits->canAccessModule($account, $module);

        if ($denied !== null) {
            return response()->json([
                'message' => $denied,
                'code' => 'PLAN_UPGRADE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
