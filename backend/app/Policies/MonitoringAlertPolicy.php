<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MonitoringAlert;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class MonitoringAlertPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MonitoringAlert $alert): bool
    {
        return $this->belongsToEffectiveAccount($user, $alert->account_id);
    }

    public function acknowledge(User $user, MonitoringAlert $alert): bool
    {
        return $this->belongsToEffectiveAccount($user, $alert->account_id)
            && in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Operator], true);
    }
}
