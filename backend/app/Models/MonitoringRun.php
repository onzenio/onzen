<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\MonitoringRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([
    'account_id', 'client_id', 'definition_code', 'status', 'idempotency_key',
    'fencing_token', 'origin', 'triggered_by', 'protocol', 'result_summary', 'failure_reason',
])]
class MonitoringRun extends Model
{
    /** @use HasFactory<MonitoringRunFactory> */
    use BelongsToAccount, HasFactory;

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const AWAITING_PROTOCOL = 'awaiting_protocol';

    public const COMPLETED = 'completed';

    public const RATE_LIMITED = 'rate_limited';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_AUTOMATIC = 'automatic';

    private const TRANSITIONS = [
        self::PENDING => [self::RUNNING, self::EXPIRED],
        self::RUNNING => [self::AWAITING_PROTOCOL, self::COMPLETED, self::RATE_LIMITED, self::FAILED, self::REJECTED, self::EXPIRED],
        self::AWAITING_PROTOCOL => [self::RUNNING, self::COMPLETED, self::FAILED, self::REJECTED, self::EXPIRED],
        self::RATE_LIMITED => [self::RUNNING, self::EXPIRED],
        self::FAILED => [self::RUNNING, self::EXPIRED],
        self::COMPLETED => [],
        self::REJECTED => [],
        self::EXPIRED => [],
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<MonitoringAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(MonitoringAttempt::class);
    }

    protected function casts(): array
    {
        return [
            'result_summary' => 'array',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::REJECTED, self::EXPIRED], true);
    }

    public function transitionTo(string $status): void
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw new InvalidArgumentException("Transição inválida: {$this->status} → {$status}.");
        }

        $this->forceFill(['status' => $status])->save();
    }
}
