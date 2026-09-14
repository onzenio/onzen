<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\MonitoringEnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'client_id', 'definition_code', 'status', 'pause_reason', 'version', 'last_checked_at'])]
class MonitoringEnrollment extends Model
{
    /** @use HasFactory<MonitoringEnrollmentFactory> */
    use BelongsToAccount, HasFactory;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const ENDED = 'ended';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function pause(string $reason): void
    {
        $this->forceFill([
            'status' => self::PAUSED,
            'pause_reason' => $reason,
            'version' => $this->version + 1,
        ])->save();
    }

    public function resume(): void
    {
        $this->forceFill([
            'status' => self::ACTIVE,
            'pause_reason' => null,
            'version' => $this->version + 1,
        ])->save();
    }

    public function end(): void
    {
        $this->forceFill([
            'status' => self::ENDED,
            'version' => $this->version + 1,
        ])->save();
    }
}
