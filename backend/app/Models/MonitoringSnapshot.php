<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'client_id', 'family', 'fingerprint', 'normalized', 'version', 'completeness', 'checked_at'])]
class MonitoringSnapshot extends Model
{
    use BelongsToAccount;

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
            'normalized' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
