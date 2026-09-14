<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\AccountCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property string $holder_name
 * @property string $thumbprint
 * @property Carbon $expires_at
 */
#[Fillable(['account_id', 'pfx_ref', 'password_ref', 'holder_name', 'thumbprint', 'expires_at'])]
class AccountCertificate extends Model
{
    /** @use HasFactory<AccountCertificateFactory> */
    use BelongsToAccount, HasFactory;

    protected $hidden = ['pfx_ref', 'password_ref'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return array{holder_name: string, thumbprint: string, expires_at: string, expired: bool}
     */
    public function toMaskedArray(): array
    {
        return [
            'holder_name' => $this->holder_name,
            'thumbprint' => $this->thumbprint,
            'expires_at' => $this->expires_at->toIso8601String(),
            'expired' => $this->isExpired(),
        ];
    }
}
