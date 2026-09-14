<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id', 'client_id', 'kind', 'idempotency_key', 'status',
    'confirmed', 'protocol', 'artifact_ref', 'failure_reason',
])]
class SerproServiceRequest extends Model
{
    use BelongsToAccount;

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const AWAITING_PROTOCOL = 'awaiting_protocol';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const KIND_DAS_PGDASD = 'emitir-das-pgdasd';

    public const KIND_DAS_PARCELAMENTO = 'emitir-das-parcelamento';

    public static function kinds(): array
    {
        return [self::KIND_DAS_PGDASD, self::KIND_DAS_PARCELAMENTO];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED, self::REJECTED], true);
    }
}
