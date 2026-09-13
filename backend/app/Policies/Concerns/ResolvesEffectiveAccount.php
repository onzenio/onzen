<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\CurrentAccount;

trait ResolvesEffectiveAccount
{
    protected function effectiveAccountId(User $user): ?int
    {
        return CurrentAccount::get() ?? $user->account_id;
    }

    protected function belongsToEffectiveAccount(User $user, int $accountId): bool
    {
        return $this->effectiveAccountId($user) === $accountId;
    }
}
