<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MonitoringArtifact;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class MonitoringArtifactPolicy
{
    use ResolvesEffectiveAccount;

    /**
     * Download is restricted to admin/operator of the artifact's effective
     * Account. `user` is denied fail-closed until a Client assignment model
     * exists, and super_admin does not bypass (same as the other policies).
     */
    public function download(User $user, MonitoringArtifact $artifact): bool
    {
        return $this->belongsToEffectiveAccount($user, $artifact->account_id)
            && in_array($user->role, [UserRole::Admin, UserRole::Operator], true);
    }
}
