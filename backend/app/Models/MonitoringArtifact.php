<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'ref', 'sha256', 'mime', 'size'])]
class MonitoringArtifact extends Model
{
    use BelongsToAccount;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
