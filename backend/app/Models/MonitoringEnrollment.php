<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\MonitoringEnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Associação de Monitoramento: a confirmed choice of monitoring a consult
 * capability for a Client. `version` is the fencing token — every relevant
 * change increments it, and a consumer holding an older version must discard
 * its result instead of overwriting the current state.
 *
 * @property int $id
 * @property int $account_id
 * @property int $client_id
 * @property string $definition_id
 * @property string $status
 * @property string|null $pause_reason
 * @property int $version
 * @property array<string, mixed>|null $configuration
 * @property Carbon|null $last_change_at
 */
#[Fillable([
    'account_id', 'client_id', 'definition_id', 'status', 'pause_reason',
    'version', 'configuration', 'last_change_at',
])]
class MonitoringEnrollment extends Model
{
    /** @use HasFactory<MonitoringEnrollmentFactory> */
    use BelongsToAccount, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<MonitoringDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MonitoringDefinition::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Pause the association with a factual reason. Repeated calls with the
     * same reason are idempotent and do not fence again.
     */
    public function pause(string $reason): void
    {
        if ($this->status === self::STATUS_PAUSED && $this->pause_reason === $reason) {
            return;
        }

        $this->transition([
            'status' => self::STATUS_PAUSED,
            'pause_reason' => $reason,
        ]);
    }

    /**
     * Resume a paused association without requiring recreation.
     */
    public function resume(): void
    {
        if ($this->isActive() && $this->pause_reason === null) {
            return;
        }

        $this->transition([
            'status' => self::STATUS_ACTIVE,
            'pause_reason' => null,
        ]);
    }

    /**
     * End the association. Runs, snapshots and alerts stay queryable; the
     * record itself is never deleted.
     */
    public function end(): void
    {
        if ($this->status === self::STATUS_ENDED) {
            return;
        }

        $this->transition([
            'status' => self::STATUS_ENDED,
            'pause_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(array $attributes): void
    {
        $this->forceFill([
            ...$attributes,
            'version' => $this->version + 1,
            'last_change_at' => now(),
        ])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'last_change_at' => 'datetime',
        ];
    }
}
