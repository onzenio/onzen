<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class ClientPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        return $this->belongsToEffectiveAccount($user, $client->account_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Operator], true);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->belongsToEffectiveAccount($user, $client->account_id)
            && $this->create($user);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }
}
