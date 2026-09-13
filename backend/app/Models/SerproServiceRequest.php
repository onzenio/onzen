<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproActionStatus;
use Database\Factories\SerproServiceRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ação fiscal explícita SERPRO (Task 27): intenção durável de emitir um DAS.
 *
 * Nunca é criada por ciclo automático nem por consulta; a criação exige
 * confirmação humana e chave de idempotência fornecidas pela interface. A
 * unicidade `(account_id, idempotency_key)` é a garantia de que repetir a
 * solicitação devolve a mesma ação sem uma segunda emissão. `document_ref` só
 * existe quando o artefato foi realmente armazenado; falha de armazenamento
 * fica registrada em `metadata.artifact` sem inventar guia baixável.
 *
 * @property int $id
 * @property int $account_id
 * @property int $client_id
 * @property int|null $enrollment_id
 * @property int|null $installment_id
 * @property string $operation_code
 * @property string|null $modality
 * @property string $idempotency_key
 * @property SerproActionStatus $status
 * @property string|null $protocol
 * @property string|null $document_ref
 * @property array<string, mixed>|null $parameters
 * @property array<string, mixed>|null $metadata
 * @property int|null $requested_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_id', 'client_id', 'enrollment_id', 'installment_id', 'operation_code',
    'modality', 'idempotency_key', 'status', 'protocol', 'document_ref', 'parameters',
    'metadata', 'requested_by_user_id',
])]
class SerproServiceRequest extends Model
{
    /** @use HasFactory<SerproServiceRequestFactory> */
    use BelongsToAccount, HasFactory;

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
     * @return BelongsTo<ParcelmentInstallment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(ParcelmentInstallment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SerproActionStatus::class,
            'parameters' => 'array',
            'metadata' => 'array',
        ];
    }
}
