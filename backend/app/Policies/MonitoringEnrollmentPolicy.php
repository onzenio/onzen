<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MonitoringEnrollment;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class MonitoringEnrollmentPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MonitoringEnrollment $enrollment): bool
    {
        return $this->belongsToEffectiveAccount($user, $enrollment->account_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Operator], true);
    }

    public function update(User $user, MonitoringEnrollment $enrollment): bool
    {
        return $this->belongsToEffectiveAccount($user, $enrollment->account_id)
            && $this->create($user);
    }

    public function delete(User $user, MonitoringEnrollment $enrollment): bool
    {
        return $this->update($user, $enrollment);
    }
}
