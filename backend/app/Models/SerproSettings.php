<?php

namespace App\Models;

use Database\Factories\SerproSettingsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Platform-level singleton holding the effective Integra Contador gate.
 *
 * Fail-closed: defaults to the homologacao environment and to a closed
 * transport until a super_admin explicitly approves it.
 *
 * @property int $id
 * @property string $environment
 * @property bool $transport_approved
 * @property Carbon|null $transport_approved_at
 * @property int|null $transport_approved_by_user_id
 */
#[Fillable(['environment', 'transport_approved', 'transport_approved_at', 'transport_approved_by_user_id'])]
class SerproSettings extends Model
{
    /** @use HasFactory<SerproSettingsFactory> */
    use HasFactory;

    public const DEFAULT_ENVIRONMENT = 'homologacao';

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'environment' => self::DEFAULT_ENVIRONMENT,
            'transport_approved' => false,
        ]);
    }

    public function environment(): string
    {
        $environment = $this->getAttribute('environment');

        return is_string($environment) && $environment !== ''
            ? $environment
            : self::DEFAULT_ENVIRONMENT;
    }

    public function transportApproved(): bool
    {
        return (bool) $this->transport_approved;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function transportApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transport_approved_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transport_approved' => 'boolean',
            'transport_approved_at' => 'datetime',
        ];
    }
}
