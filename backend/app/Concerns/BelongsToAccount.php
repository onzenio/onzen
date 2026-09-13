<?php

namespace App\Concerns;

use App\Support\CurrentAccount;

trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', fn ($q) => $q->when(
            CurrentAccount::get(),
            fn ($q, $id) => $q->where($q->getModel()->getTable().'.account_id', $id)
        ));

        static::creating(fn ($m) => $m->account_id ??= CurrentAccount::get());
    }
}
