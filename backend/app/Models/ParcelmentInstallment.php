<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\ParcelmentInstallmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Parcela normalizada de um pedido de parcelamento.
 *
 * `guide_ref` guarda a referência opaca do artefato (Task 6) quando uma guia
 * já foi emitida/armazenada; o download da guia lê apenas esse ref e nunca
 * dispara emissão. Repetir a projeção da mesma operação converge pelo par
 * `(account, order, number)` e preserva o `guide_ref` existente.
 *
 * @property int $id
 * @property int $account_id
 * @property int $client_id
 * @property int $order_id
 * @property string $external_id
 * @property int $number
 * @property string|null $status
 * @property string|null $amount
 * @property Carbon|null $due_date
 * @property Carbon|null $paid_at
 * @property string|null $guide_ref
 * @property string $provenance
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'account_id', 'client_id', 'order_id', 'external_id', 'number', 'status', 'amount',
    'due_date', 'paid_at', 'guide_ref', 'provenance', 'metadata',
])]
class ParcelmentInstallment extends Model
{
    /** @use HasFactory<ParcelmentInstallmentFactory> */
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
     * @return BelongsTo<ParcelmentOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ParcelmentOrder::class, 'order_id');
    }

    /**
     * @return HasMany<ParcelmentPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ParcelmentPayment::class, 'installment_id');
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
            'due_date' => 'date',
            'paid_at' => 'date',
            'amount' => 'decimal:2',
        ];
    }
}
