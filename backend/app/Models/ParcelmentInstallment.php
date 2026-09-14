<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['parcelment_order_id', 'number', 'due_date', 'value', 'status'])]
class ParcelmentInstallment extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(ParcelmentOrder::class, 'parcelment_order_id');
    }
}
