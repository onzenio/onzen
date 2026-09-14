<?php

namespace App\Models;

use Database\Factories\MonitoringAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitoring_run_id', 'attempt_number', 'status_code', 'outcome', 'response_summary', 'backoff_seconds'])]
class MonitoringAttempt extends Model
{
    /** @use HasFactory<MonitoringAttemptFactory> */
    use HasFactory;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_TRANSIENT = 'transient';

    public const OUTCOME_FATAL = 'fatal';

    public const OUTCOME_PROTOCOL = 'protocol';

    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitoringRun::class, 'monitoring_run_id');
    }

    protected function casts(): array
    {
        return [
            'response_summary' => 'array',
        ];
    }
}
