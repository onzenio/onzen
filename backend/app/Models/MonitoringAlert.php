<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\MonitoringAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alerta acionável de uma mudança relevante. Nasce `pending`, criado uma única
 * vez por mudança (`change_id` único), e é reconhecido de forma idempotente e
 * auditada por `admin`/`operator` da Account.
 *
 * @property int $id
 * @property int $account_id
 * @property int $enrollment_id
 * @property int $client_id
 * @property int $change_id
 * @property string $status
 * @property int|null $acknowledged_by_user_id
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'account_id', 'enrollment_id', 'client_id', 'change_id', 'status',
    'acknowledged_by_user_id', 'acknowledged_at',
])]
class MonitoringAlert extends Model
{
    /** @use HasFactory<MonitoringAlertFactory> */
    use BelongsToAccount, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<MonitoringEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(MonitoringEnrollment::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<MonitoringChange, $this>
     */
    public function change(): BelongsTo
    {
        return $this->belongsTo(MonitoringChange::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
