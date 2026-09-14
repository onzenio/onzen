<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'monitoring_snapshot_id', 'client_id', 'family', 'change_type', 'summary'])]
class MonitoringChange extends Model
{
    use BelongsToAccount;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MonitoringSnapshot::class, 'monitoring_snapshot_id');
    }

    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }
}
