<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class AccountPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Account $account): bool
    {
        return $user->isSuperAdmin() || $this->belongsToEffectiveAccount($user, $account->id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
