<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Gestão do Certificado Digital da Account: somente `admin` e `super_admin`
 * (operador e usuário recebem 403). O certificado é um singleton por Account
 * efetiva, então as abilities são avaliadas contra a classe.
 */
class AccountCertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user): bool
    {
        return $this->viewAny($user);
    }
}
