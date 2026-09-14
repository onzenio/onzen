<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SerproRequestAuthor;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

class SerproRequestAuthorPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SerproRequestAuthor $author): bool
    {
        return $this->belongsToEffectiveAccount($user, $author->account_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin], true);
    }

    public function update(User $user, SerproRequestAuthor $author): bool
    {
        return $this->belongsToEffectiveAccount($user, $author->account_id)
            && $this->create($user);
    }

    public function delete(User $user, SerproRequestAuthor $author): bool
    {
        return $this->update($user, $author);
    }
}
