<?php

namespace App\Policies;

use App\Enums\AccountProfile;
use App\Models\Account;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

/**
 * Administração SERPRO (credenciais, ambiente e transporte) restrita à
 * Account A.
 *
 * Só `super_admin` gerencia, e apenas quando a conta efetiva é a plataforma
 * (profile A): um `super_admin` operando no contexto de outra Account recebe
 * 403, assim como `admin`, `operator` e `user`.
 */
class SerproAdminPolicy
{
    use ResolvesEffectiveAccount;

    public function manage(User $user): bool
    {
        if (! $user->isSuperAdmin()) {
            return false;
        }

        $accountId = $this->effectiveAccountId($user);

        if ($accountId === null) {
            return false;
        }

        return Account::query()
            ->whereKey($accountId)
            ->where('profile', AccountProfile::A)
            ->exists();
    }
}
