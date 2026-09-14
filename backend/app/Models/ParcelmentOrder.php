<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\ParcelmentOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pedido de parcelamento normalizado (Simples Nacional/MEI).
 *
 * Cada linha carrega o vínculo com a Account e com o Client; o vínculo com a
 * Associação registra de qual enrollment a consulta veio. Repetir a projeção
 * da mesma operação converge pelo par natural
 * `(account, client, modality, external_id)` — nunca duplica pedido.
 *
 * @property int $id
 * @property int $account_id
 * @property int $client_id
 * @property int|null $enrollment_id
 * @property string $modality
 * @property string $external_id
 * @property string|null $status
 * @property int|null $installments_count
 * @property string|null $total_amount
 * @property Carbon|null $competence
 * @property string $provenance
 * @property string|null $operation_code
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'account_id', 'client_id', 'enrollment_id', 'modality', 'external_id', 'status',
    'installments_count', 'total_amount', 'competence', 'provenance', 'operation_code',
    'metadata',
])]
class ParcelmentOrder extends Model
{
    /** @use HasFactory<ParcelmentOrderFactory> */
    use BelongsToAccount, HasFactory;

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
     * @return BelongsTo<MonitoringEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(MonitoringEnrollment::class);
    }

    /**
     * @return HasMany<ParcelmentInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(ParcelmentInstallment::class, 'order_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'competence' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }
}
