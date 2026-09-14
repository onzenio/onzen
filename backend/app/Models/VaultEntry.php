<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Encrypted vault storage. `value` always holds ciphertext produced with the
 * application key; `type` records whether the plaintext was a string or a
 * JSON-encoded array so reads preserve the original PHP type.
 *
 * @property string $ref
 * @property string $type
 * @property string $value
 */
#[Fillable(['ref', 'type', 'value'])]
class VaultEntry extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'ref';

    protected $keyType = 'string';
}
