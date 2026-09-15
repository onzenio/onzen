<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Closure;
use Database\Factories\MonitoringEnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function isEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    /**
     * Pause the association with a factual reason. Repeated calls with the
     * same reason are idempotent and do not fence again. An ended
     * association is never revived.
     *
     * @return bool Whether the association changed.
     */
    public function pause(string $reason): bool
    {
        return $this->transition(function (self $fresh) use ($reason): ?array {
            if ($fresh->isEnded()) {
                return null;
            }

            if ($fresh->status === self::STATUS_PAUSED && $fresh->pause_reason === $reason) {
                return null;
            }

            return [
                'status' => self::STATUS_PAUSED,
                'pause_reason' => $reason,
            ];
        });
    }

    /**
     * Resume a paused association without requiring recreation. An ended
     * association is never revived.
     *
     * @return bool Whether the association changed.
     */
    public function resume(): bool
    {
        return $this->transition(function (self $fresh): ?array {
            if ($fresh->isEnded()) {
                return null;
            }

            if ($fresh->isActive() && $fresh->pause_reason === null) {
                return null;
            }

            return [
                'status' => self::STATUS_ACTIVE,
                'pause_reason' => null,
            ];
        });
    }

    /**
     * End the association. Runs, snapshots and alerts stay queryable; the
     * record itself is never deleted.
     *
     * @return bool Whether the association changed.
     */
    public function end(): bool
    {
        return $this->transition(function (self $fresh): ?array {
            if ($fresh->isEnded()) {
                return null;
            }

            return [
                'status' => self::STATUS_ENDED,
                'pause_reason' => null,
            ];
        });
    }

    /**
     * Replace the configuration. An ended association is never changed.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return bool Whether the association changed.
     */
    public function configure(?array $configuration): bool
    {
        return $this->transition(function (self $fresh) use ($configuration): ?array {
            if ($fresh->isEnded()) {
                return null;
            }

            return ['configuration' => $configuration];
        });
    }

    /**
     * Apply a fenced transition atomically.
     *
     * The row is locked and read fresh inside the transaction, so the guard
     * sees the committed state and the new version is always
     * `locked version + 1`. Concurrent transitions from stale instances
     * therefore mint strictly increasing versions instead of repeating one.
     *
     * @param  Closure(self): (array<string, mixed>|null)  $resolve
     * @return bool Whether the association changed.
     */
    private function transition(Closure $resolve): bool
    {
        $changed = DB::transaction(function () use ($resolve): bool {
            // Idem MonitoringRun::transitionTo: sem escopo global (jobs sem
            // CurrentAccount) + account_id da própria linha como tenancy.
            $fresh = static::query()
                ->withoutGlobalScope('account')
                ->whereKey($this->getKey())
                ->where('account_id', $this->account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = $resolve($fresh);

            if ($attributes === null) {
                return false;
            }

            $fresh->forceFill([
                ...$attributes,
                'version' => $fresh->version + 1,
                'last_change_at' => now(),
            ])->save();

            return true;
        });

        if ($changed) {
            $this->refresh();
        }

        return $changed;
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
