<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\MonitoringSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Snapshot de resultado: uma versão publicada do resultado normalizado de uma
 * operação de consulta para uma Associação de Monitoramento.
 *
 * O `fingerprint` é o hash determinístico do resultado normalizado; repetir a
 * consulta sem mudança atualiza `verified_at` da versão vigente sem criar
 * versão nova. Resultados incompletos, stale ou bloqueados nunca publicam.
 *
 * @property int $id
 * @property int $account_id
 * @property int $enrollment_id
 * @property int $client_id
 * @property int|null $run_id
 * @property string $operation_code
 * @property string $family
 * @property bool $normalized
 * @property string $fingerprint
 * @property array<string, mixed> $data
 * @property string $freshness
 * @property string $completeness
 * @property Carbon $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_id', 'enrollment_id', 'client_id', 'run_id', 'operation_code',
    'family', 'normalized', 'fingerprint', 'data', 'freshness', 'completeness',
    'verified_at',
])]
class MonitoringSnapshot extends Model
{
    /** @use HasFactory<MonitoringSnapshotFactory> */
    use BelongsToAccount, HasFactory;

    public const FRESHNESS_FRESH = 'fresh';

    public const FRESHNESS_STALE = 'stale';

    public const COMPLETENESS_COMPLETE = 'complete';

    public const COMPLETENESS_INCOMPLETE = 'incomplete';

    public const COMPLETENESS_BLOCKED = 'blocked';

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
     * @return BelongsTo<MonitoringRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitoringRun::class, 'run_id');
    }

    /**
     * @return HasOne<MonitoringChange, $this>
     */
    public function change(): HasOne
    {
        return $this->hasOne(MonitoringChange::class, 'snapshot_id');
    }

    public function isComplete(): bool
    {
        return $this->completeness === self::COMPLETENESS_COMPLETE;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'normalized' => 'boolean',
            'data' => 'array',
            'verified_at' => 'datetime',
        ];
    }
}
