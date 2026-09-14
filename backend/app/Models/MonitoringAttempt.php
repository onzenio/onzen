<?php

namespace App\Models;

use App\Enums\MonitoringRunStatus;
use Database\Factories\MonitoringAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tentativa de execução: one attempt recorded under a Monitoring run,
 * carrying the factual classification of that attempt.
 *
 * Attempts are Account-scoped through their run, never directly.
 *
 * @property int $id
 * @property int $run_id
 * @property int $attempt
 * @property MonitoringRunStatus $status
 * @property int|null $response_code
 * @property string|null $classification
 * @property int|null $retry_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'run_id', 'attempt', 'status', 'response_code', 'classification', 'retry_after',
])]
class MonitoringAttempt extends Model
{
    /** @use HasFactory<MonitoringAttemptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MonitoringRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitoringRun::class, 'run_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MonitoringRunStatus::class,
        ];
    }
}
