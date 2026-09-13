<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property string $cnpj
 * @property string $razao_social
 * @property string $regime
 * @property string $contador_responsavel
 * @property bool $monitoring_enabled
 */
#[Fillable(['account_id', 'cnpj', 'razao_social', 'regime', 'contador_responsavel', 'monitoring_enabled'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitoring_enabled' => 'boolean',
        ];
    }
}
