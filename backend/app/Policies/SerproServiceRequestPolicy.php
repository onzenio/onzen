<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SerproServiceRequest;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class SerproServiceRequestPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Reading an action stays with Account members; cross-account resources
     * are already an indistinguishable 404 through the account global scope.
     */
    public function view(User $user, SerproServiceRequest $action): bool
    {
        return $this->belongsToEffectiveAccount($user, $action->account_id);
    }

    /**
     * Emission is an explicit fiscal side effect: admin/operator only, same
     * matrix as the other monitoring writes. `user` is denied fail-closed.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Operator], true);
    }
}
