<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'monitoring_run_id', 'origin', 'period'])]
class MonitoringQuotaUsage extends Model
{
    use BelongsToAccount;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitoringRun::class, 'monitoring_run_id');
    }
}
