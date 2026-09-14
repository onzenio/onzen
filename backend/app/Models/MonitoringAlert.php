<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'client_id', 'family', 'monitoring_change_id', 'status', 'acknowledged_by', 'acknowledged_at'])]
class MonitoringAlert extends Model
{
    use BelongsToAccount;

    public const OPEN = 'open';

    public const ACKNOWLEDGED = 'acknowledged';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(MonitoringChange::class, 'monitoring_change_id');
    }

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    public function isAcknowledged(): bool
    {
        return $this->status === self::ACKNOWLEDGED;
    }
}
