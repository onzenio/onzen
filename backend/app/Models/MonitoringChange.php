<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\MonitoringChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Mudança detectada: a transição entre duas versões publicadas do resultado
 * normalizado de uma Associação de Monitoramento. Cada snapshot novo com
 * fingerprint diferente gera no máximo uma mudança (`snapshot_id` único), e
 * cada mudança gera no máximo um alerta.
 *
 * @property int $id
 * @property int $account_id
 * @property int $enrollment_id
 * @property int $client_id
 * @property int $snapshot_id
 * @property int|null $previous_snapshot_id
 * @property int|null $run_id
 * @property string $operation_code
 * @property string $kind
 * @property array<string, mixed> $data
 * @property Carbon|null $created_at
 */
#[Fillable([
    'account_id', 'enrollment_id', 'client_id', 'snapshot_id',
    'previous_snapshot_id', 'run_id', 'operation_code', 'kind', 'data',
])]
class MonitoringChange extends Model
{
    /** @use HasFactory<MonitoringChangeFactory> */
    use BelongsToAccount, HasFactory;

    public const KIND_CHANGED = 'changed';

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<MonitoringEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(MonitoringEnrollment::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<MonitoringSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MonitoringSnapshot::class);
    }

    /**
     * @return BelongsTo<MonitoringSnapshot, $this>
     */
    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(MonitoringSnapshot::class, 'previous_snapshot_id');
    }

    /**
     * @return BelongsTo<MonitoringRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitoringRun::class, 'run_id');
    }

    /**
     * @return HasOne<MonitoringAlert, $this>
     */
    public function alert(): HasOne
    {
        return $this->hasOne(MonitoringAlert::class, 'change_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
