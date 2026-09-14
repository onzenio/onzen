<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\MonitoringRunStatus;
use App\Exceptions\InvalidRunTransitionException;
use Database\Factories\MonitoringRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Execução de monitoramento: one attempt to run a consult operation for an
 * Associação de Monitoramento.
 *
 * Idempotency is the unique `(account_id, idempotency_key)` pair: repeating a
 * request returns the existing run instead of creating traffic. `fencing_token`
 * captures the `MonitoringEnrollment.version` at claim time; a run whose token
 * no longer matches the current enrollment version is superseded and its
 * result is discarded (`discarded`), never projected.
 *
 * @property int $id
 * @property int $account_id
 * @property int $enrollment_id
 * @property string $trigger
 * @property string $definition_key
 * @property string|null $operation_code
 * @property string $idempotency_key
 * @property int $fencing_token
 * @property MonitoringRunStatus $status
 * @property string $environment
 * @property bool $dry_run
 * @property string|null $protocol
 * @property Carbon|null $eta
 * @property array<string, mixed>|null $parameters
 * @property string|null $external_code
 * @property string|null $error_code
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_id', 'enrollment_id', 'trigger', 'definition_key', 'operation_code',
    'idempotency_key', 'fencing_token', 'status', 'environment', 'dry_run',
    'protocol', 'eta', 'parameters', 'external_code', 'error_code',
    'started_at', 'finished_at',
])]
class MonitoringRun extends Model
{
    /** @use HasFactory<MonitoringRunFactory> */
    use BelongsToAccount, HasFactory;

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_AUTOMATIC = 'automatic';

    public const DISCARDS_SUPERSEDED = 'superseded';

    /**
     * @return BelongsTo<MonitoringEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(MonitoringEnrollment::class);
    }

    /**
     * @return HasMany<MonitoringAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(MonitoringAttempt::class, 'run_id');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Move the run through the state machine atomically.
     *
     * The row is locked and read fresh, so a stale instance can never replay
     * a transition or reset timestamps. `running` stamps `started_at` once;
     * terminal states stamp `finished_at`; leaving a retryable state clears
     * it again. Any disallowed edge refuses with a factual
     * {@see InvalidRunTransitionException}.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(MonitoringRunStatus $status, array $attributes = []): self
    {
        if (! $this->status->canTransitionTo($status)) {
            throw InvalidRunTransitionException::from($this->status, $status);
        }

        $fresh = DB::transaction(function () use ($status, $attributes): self {
            $fresh = static::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->status->canTransitionTo($status)) {
                throw InvalidRunTransitionException::from($fresh->status, $status);
            }

            $fresh->forceFill([
                ...$attributes,
                'status' => $status,
                'started_at' => $fresh->started_at ?? ($status === MonitoringRunStatus::Running ? now() : null),
                'finished_at' => $status->isTerminal() ? now() : null,
            ])->save();

            return $fresh;
        });

        // Keep the caller's instance usable for chained transitions.
        $this->setRawAttributes($fresh->getAttributes(), true);

        return $fresh;
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
            'dry_run' => 'boolean',
            'eta' => 'datetime',
            'parameters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
