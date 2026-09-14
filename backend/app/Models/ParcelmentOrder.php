<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'client_id', 'modality_code', 'order_number', 'status', 'total_value', 'guia_ref'])]
class ParcelmentOrder extends Model
{
    use BelongsToAccount;

    protected $hidden = ['guia_ref'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<ParcelmentInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(ParcelmentInstallment::class);
    }

    /**
     * @return HasMany<ParcelmentPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ParcelmentPayment::class);
    }
}
