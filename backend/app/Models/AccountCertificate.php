<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Services\AuditService;
use App\Support\VaultRef;
use Carbon\CarbonInterface;
use Database\Factories\AccountCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single active A1 certificate of an Account. Replacement happens in
 * place: the previous vault reference stops being referenced and the linked
 * authors are resynced to the new certificate in the same transaction.
 *
 * @property int $id
 * @property int $account_id
 * @property string $vault_ref
 * @property string $holder_name
 * @property string $thumbprint
 * @property Carbon $expires_at
 * @property int|null $uploaded_by_user_id
 */
#[Fillable(['account_id', 'vault_ref', 'holder_name', 'thumbprint', 'expires_at', 'uploaded_by_user_id'])]
#[Hidden(['vault_ref'])]
class AccountCertificate extends Model
{
    /** @use HasFactory<AccountCertificateFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at === null
            || $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        return ! $this->isExpired($at);
    }

    public function maskedVaultRef(): ?string
    {
        return VaultRef::mask($this->vault_ref);
    }

    /**
     * Replace the stored certificate, keeping a single active version.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function replace(array $attributes, ?User $actor = null): self
    {
        return DB::transaction(function () use ($attributes, $actor): self {
            $previousThumbprint = $this->thumbprint;
            $previousExpiresAt = $this->expires_at;

            $this->fill($attributes);
            $this->save();

            SerproRequestAuthor::query()
                ->withoutGlobalScope('account')
                ->where('account_id', $this->account_id)
                ->get()
                ->each(fn (SerproRequestAuthor $author) => $author->useCertificate($this));

            app(AuditService::class)->record($actor, 'account.certificate_replaced', [
                'account_id' => $this->account_id,
                'previous_thumbprint' => $previousThumbprint,
                'previous_expires_at' => $previousExpiresAt?->toIso8601String(),
                'thumbprint' => $this->thumbprint,
                'expires_at' => $this->expires_at?->toIso8601String(),
            ], $this->account);

            return $this;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
