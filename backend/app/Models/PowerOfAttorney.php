<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'client_id', 'service_code', 'status', 'verified_at'])]
class PowerOfAttorney extends Model
{
    use BelongsToAccount;

    protected $table = 'powers_of_attorney';

    public const VALID = 'valid';

    public const MISSING = 'missing';

    public const EXPIRED = 'expired';

    public const DIVERGENT = 'divergent';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }
}
