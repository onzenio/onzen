<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\MonitoringArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ref
 * @property int $account_id
 * @property int|null $client_id
 * @property int|null $enrollment_id
 * @property string $kind
 * @property string|null $source
 * @property string|null $original_name
 * @property string $storage_path
 * @property string $hash_sha256
 * @property Carbon|null $created_at
 */
#[Fillable([
    'ref',
    'account_id',
    'client_id',
    'enrollment_id',
    'kind',
    'source',
    'original_name',
    'storage_path',
    'hash_sha256',
])]
#[Hidden(['storage_path'])]
class MonitoringArtifact extends Model
{
    /** @use HasFactory<MonitoringArtifactFactory> */
    use BelongsToAccount, HasFactory;

    public const UPDATED_AT = null;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
