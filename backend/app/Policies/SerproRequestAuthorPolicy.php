<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SerproRequestAuthor;
use App\Models\User;
use App\Policies\Concerns\ResolvesEffectiveAccount;

/**
 * Gestão dos Autores do Pedido de Dados: somente `admin` e `super_admin`
 * (operador e usuário recebem 403). Leitura e escrita são sempre limitadas à
 * Account efetiva.
 */
class SerproRequestAuthorPolicy
{
    use ResolvesEffectiveAccount;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, SerproRequestAuthor $author): bool
    {
        return $this->viewAny($user)
            && $this->belongsToEffectiveAccount($user, $author->account_id);
    }

    public function update(User $user, SerproRequestAuthor $author): bool
    {
        return $this->view($user, $author);
    }
}
