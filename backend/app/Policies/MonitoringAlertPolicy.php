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

    /**
     * Acknowledgement follows the monitoring write rule: `admin`/`operator`
     * of the effective Account (and super_admin); `user` is read-only.
     */
    public function acknowledge(User $user, MonitoringAlert $alert): bool
    {
        return $this->view($user, $alert)
            && in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Operator], true);
    }
}
