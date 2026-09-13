<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;

class PlanLimitService
{
    public function canInvite(Account $account): ?string
    {
        return $this->usersOk($account, 1)
            ? null
            : 'Limite de usuários do plano atingido. Faça upgrade do plano.';
    }

    public function canAccept(Invitation $invitation): ?string
    {
        $account = $invitation->account;

        if ($account === null || $account->plan === null) {
            return null;
        }

        $used = $this->activeUsers($account)
            + $this->validPendingInvitations($account, $invitation->id)->count();

        return $used < $account->plan->max_users
            ? null
            : 'Limite de usuários do plano atingido. Faça upgrade do plano.';
    }

    public function canCreateClient(Account $account): ?string
    {
        if ($account->plan === null) {
            return null;
        }

        $total = Client::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->count();

        return $total < $account->plan->max_clients
            ? null
            : 'Limite de clientes do plano atingido. Faça upgrade do plano.';
    }

    public function canAccessModule(Account $account, string $module): ?string
    {
        if ($account->plan === null) {
            return null;
        }

        return in_array($module, $account->plan->modules ?? [], true)
            ? null
            : 'Módulo não liberado no plano vigente. Faça upgrade do plano.';
    }

    protected function usersOk(Account $account, int $extra): bool
    {
        if ($account->plan === null) {
            return true;
        }

        $used = $this->activeUsers($account)
            + $this->validPendingInvitations($account)->count();

        return ($used + $extra) <= $account->plan->max_users;
    }

    protected function activeUsers(Account $account): int
    {
        return User::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->count();
    }

    protected function validPendingInvitations(Account $account, ?int $exceptId = null)
    {
        return Invitation::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId));
    }
}
