<?php

namespace App\Services\Monitoring;

use App\Enums\UserRole;
use App\Models\MonitoringAlert;
use App\Models\User;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ações de alertas de monitoramento (Task 23).
 *
 * O reconhecimento é restrito a `admin`/`operator` (e `super_admin`) da
 * Account efetiva, idempotente (reconhecer um alerta já reconhecido devolve o
 * mesmo estado, sem duplicar registro nem auditoria) e auditado sem payload
 * fiscal. A rota `POST /api/monitoring/alerts/{id}/acknowledge` é exposta na
 * Task 25.
 */
final class MonitoringAlertService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @throws AuthorizationException
     */
    public function acknowledge(MonitoringAlert $alert, User $actor): MonitoringAlert
    {
        $this->assertAuthorized($alert, $actor);

        return DB::transaction(function () use ($alert, $actor): MonitoringAlert {
            $fresh = MonitoringAlert::query()
                ->withoutGlobalScope('account')
                ->whereKey($alert->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->isAcknowledged()) {
                return $fresh;
            }

            $fresh->forceFill([
                'status' => MonitoringAlert::STATUS_ACKNOWLEDGED,
                'acknowledged_by_user_id' => $actor->getKey(),
                'acknowledged_at' => now(),
            ])->save();

            $this->audit->record($actor, 'monitoring.alert.acknowledged', [
                'alert_id' => $fresh->getKey(),
                'enrollment_id' => $fresh->enrollment_id,
                'client_id' => $fresh->client_id,
                'change_id' => $fresh->change_id,
            ], $fresh->account);

            return $fresh;
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function assertAuthorized(MonitoringAlert $alert, User $actor): void
    {
        $effectiveAccount = CurrentAccount::get() ?? $actor->account_id;

        $allowed = $effectiveAccount === $alert->account_id
            && in_array($actor->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Operator], true);

        if (! $allowed) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
