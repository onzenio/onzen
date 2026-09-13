<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Reserva imutável de uma unidade do `Plan.monthly_query_volume`.
 *
 * A unique `run_id` torna a reserva idempotente por execução: repetir o mesmo
 * run nunca debita duas vezes. Não há `updated_at` nem rota de escrita — o
 * consumo é factual e não devolve volume quando a execução falha depois de
 * iniciada (design decision 7).
 *
 * @property int $id
 * @property int $account_id
 * @property int $run_id
 * @property string $trigger
 * @property string $period
 * @property Carbon|null $created_at
 */
#[Fillable(['account_id', 'run_id', 'trigger', 'period'])]
class QueryQuotaConsumption extends Model
{
    use BelongsToAccount;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<MonitoringRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitoringRun::class, 'run_id');
    }
}
