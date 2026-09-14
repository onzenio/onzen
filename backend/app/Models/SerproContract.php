<?php

namespace App\Models;

use Database\Factories\SerproContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $environment
 * @property string|null $contractor_document
 * @property string|null $consumer_key_ref
 * @property string|null $consumer_secret_ref
 * @property bool $transport_approved
 */
#[Fillable(['environment', 'consumer_key_ref', 'consumer_secret_ref', 'transport_approved', 'contractor_document'])]
class SerproContract extends Model
{
    /** @use HasFactory<SerproContractFactory> */
    use HasFactory;

    public const ENV_HOMOLOGACAO = 'homologacao';

    public const ENV_PRODUCAO = 'producao';

    protected function casts(): array
    {
        return [
            'transport_approved' => 'boolean',
        ];
    }

    public static function maskRef(?string $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        $tail = substr($ref, -4);

        return 'secret:***'.$tail;
    }

    /**
     * @return array{environment: string, contractor_document_masked: string|null, consumer_key_masked: string|null, consumer_secret_masked: string|null, transport_approved: bool}
     */
    public function toMaskedArray(): array
    {
        return [
            'environment' => $this->environment,
            'contractor_document_masked' => $this->contractor_document
                ? substr($this->contractor_document, 0, 2).'.***.'.substr($this->contractor_document, -2)
                : null,
            'consumer_key_masked' => self::maskRef($this->consumer_key_ref),
            'consumer_secret_masked' => self::maskRef($this->consumer_secret_ref),
            'transport_approved' => $this->transport_approved,
        ];
    }
}
