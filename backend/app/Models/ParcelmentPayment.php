<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['parcelment_order_id', 'paid_at', 'value', 'receipt'])]
class ParcelmentPayment extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(ParcelmentOrder::class, 'parcelment_order_id');
    }
}
