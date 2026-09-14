<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\SerproRequestAuthorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property int|null $account_certificate_id
 * @property string $document
 * @property string $name
 * @property string $status
 * @property string|null $token_ref
 */
#[Fillable(['account_id', 'account_certificate_id', 'document', 'name', 'status', 'token_ref', 'token_expires_at'])]
class SerproRequestAuthor extends Model
{
    /** @use HasFactory<SerproRequestAuthorFactory> */
    use BelongsToAccount, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INELIGIBLE = 'ineligible';

    protected $hidden = ['token_ref'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(AccountCertificate::class, 'account_certificate_id');
    }

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
        ];
    }

    public function isEligible(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $cert = $this->certificate;

        if ($cert === null) {
            return false;
        }

        return ! $cert->isExpired();
    }

    /**
     * @return array{document: string, name: string, status: string, eligible: bool, certificate_thumbprint: string|null}
     */
    public function toMaskedArray(): array
    {
        return [
            'document' => $this->document,
            'name' => $this->name,
            'status' => $this->status,
            'eligible' => $this->isEligible(),
            'certificate_thumbprint' => $this->certificate?->thumbprint,
        ];
    }
}
