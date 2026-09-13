<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\UserRole;
use Carbon\CarbonInterface;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string $token_hash
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property int|null $invited_by_user_id
 */
#[Fillable(['account_id', 'name', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at', 'invited_by_user_id'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
