<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Integrations\Serpro\ProcurationCatalog;
use Carbon\CarbonInterface;
use Database\Factories\PowerOfAttorneyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Confirmed outorga of a Client service code, as returned by the official
 * `OBTERPROCURACAO41` verification and mapped through
 * {@see ProcurationCatalog}.
 *
 * @property int $id
 * @property int $account_id
 * @property int $client_id
 * @property string $code
 * @property string $status
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['account_id', 'client_id', 'code', 'status', 'valid_from', 'valid_until', 'metadata'])]
class PowerOfAttorney extends Model
{
    /** @use HasFactory<PowerOfAttorneyFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * Keep the table name aligned with the SERPRO domain migration;
     * Laravel's pluralizer would otherwise generate `power_of_attorneys`.
     */
    protected $table = 'powers_of_attorney';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

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

    public function isValid(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_ACTIVE
            && ($this->valid_from === null || ! $this->valid_from->greaterThan($at))
            && ($this->valid_until === null || $this->valid_until->greaterThan($at));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
