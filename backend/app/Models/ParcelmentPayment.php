<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\ParcelmentPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pagamento normalizado de uma parcela de parcelamento.
 *
 * Repetir a projeção da mesma operação converge pelo par
 * `(account, installment, external_id)` — o identificador é sempre derivado
 * de forma determinística para nunca duplicar pagamento.
 *
 * @property int $id
 * @property int $account_id
 * @property int $client_id
 * @property int $installment_id
 * @property string|null $external_id
 * @property string|null $status
 * @property string|null $amount
 * @property Carbon|null $paid_at
 * @property string|null $receipt_ref
 * @property string $provenance
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'account_id', 'client_id', 'installment_id', 'external_id', 'status', 'amount',
    'paid_at', 'receipt_ref', 'provenance', 'metadata',
])]
class ParcelmentPayment extends Model
{
    /** @use HasFactory<ParcelmentPaymentFactory> */
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
     * @return BelongsTo<ParcelmentInstallment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(ParcelmentInstallment::class, 'installment_id');
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
            'paid_at' => 'date',
            'amount' => 'decimal:2',
        ];
    }
}
