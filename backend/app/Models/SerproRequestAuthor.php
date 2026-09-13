<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\AuthorDocumentType;
use App\Enums\AuthorStatus;
use Carbon\CarbonInterface;
use Database\Factories\SerproRequestAuthorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Author of the Data Request whose signature uses the Account certificate.
 * Eligibility is fail-closed: without a valid, unexpired certificate the
 * author is ineligible.
 *
 * @property int $id
 * @property int $account_id
 * @property string $document
 * @property AuthorDocumentType $document_type
 * @property string $name
 * @property AuthorStatus $status
 * @property string|null $certificate_thumbprint
 * @property Carbon|null $certificate_expires_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'account_id', 'document', 'document_type', 'name', 'status',
    'certificate_thumbprint', 'certificate_expires_at', 'metadata',
])]
class SerproRequestAuthor extends Model
{
    /** @use HasFactory<SerproRequestAuthorFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Bind the author to the Account certificate, inheriting thumbprint and
     * expiry, and persist the resulting eligibility.
     */
    public function useCertificate(AccountCertificate $certificate): self
    {
        $this->certificate_thumbprint = $certificate->thumbprint;
        $this->certificate_expires_at = $certificate->expires_at;
        $this->status = $this->resolveStatus();
        $this->save();

        return $this;
    }

    /**
     * Re-evaluate eligibility against the linked certificate validity.
     */
    public function refreshEligibility(?CarbonInterface $at = null): self
    {
        $status = $this->resolveStatus($at);

        if ($this->status !== $status) {
            $this->status = $status;
            $this->save();
        }

        return $this;
    }

    public function isEligible(?CarbonInterface $at = null): bool
    {
        return $this->status === AuthorStatus::Active && $this->hasValidCertificate($at);
    }

    private function resolveStatus(?CarbonInterface $at = null): AuthorStatus
    {
        return $this->hasValidCertificate($at) ? AuthorStatus::Active : AuthorStatus::Ineligible;
    }

    private function hasValidCertificate(?CarbonInterface $at = null): bool
    {
        return $this->certificate_expires_at !== null
            && $this->certificate_expires_at->greaterThan($at ?? now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => AuthorDocumentType::class,
            'status' => AuthorStatus::class,
            'certificate_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
