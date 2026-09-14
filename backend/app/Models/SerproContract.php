<?php

namespace App\Models;

use App\Support\VaultRef;
use Database\Factories\SerproContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $environment
 * @property string|null $credential_ref
 * @property int|null $updated_by_user_id
 */
#[Fillable(['environment', 'credential_ref', 'updated_by_user_id'])]
#[Hidden(['credential_ref'])]
class SerproContract extends Model
{
    /** @use HasFactory<SerproContractFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function maskedCredentialRef(): ?string
    {
        return VaultRef::mask($this->credential_ref);
    }

    public function hasCredentials(): bool
    {
        return is_string($this->credential_ref) && $this->credential_ref !== '';
    }
}
