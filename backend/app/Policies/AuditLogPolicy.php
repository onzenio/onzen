<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class AuditLogPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->role === UserRole::Admin;
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->role !== UserRole::Admin) {
            return false;
        }

        if ($this->belongsToEffectiveAccount($user, $auditLog->origin_account_id)) {
            return true;
        }

        return $auditLog->target_account_id !== null
            && $this->belongsToEffectiveAccount($user, $auditLog->target_account_id);
    }
}
