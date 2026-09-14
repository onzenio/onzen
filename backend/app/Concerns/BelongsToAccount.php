<?php

namespace App\Concerns;

use App\Support\CurrentAccount;

trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        // Fail-closed: sem Account efetiva nenhum registro é visível
        // (antes o `when(null)` removia o where e vazava todas as Accounts).
        static::addGlobalScope('account', function ($q): void {
            $id = CurrentAccount::get();
            if ($id === null) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where($q->getModel()->getTable().'.account_id', $id);
            }
        });

        static::creating(fn ($m) => $m->account_id ??= CurrentAccount::get());
    }
}
