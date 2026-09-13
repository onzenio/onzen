<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class InvitationPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin], true);
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return $this->belongsToEffectiveAccount($user, $invitation->account_id)
            && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }
}
