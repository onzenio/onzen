<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringSnapshot;
use App\Models\User;

class SnapshotService
{
    public function __construct(private readonly AuditService $audit) {}

    public static function fingerprint(array $normalized): string
    {
        $sorted = self::sortRecursive($normalized);

        return hash('sha256', json_encode($sorted));
    }

    /**
     * Registra o resultado: sem mudança de fingerprint, só atualiza a
     * verificação; com mudança, versiona + mudança + alerta.
     */
    public function record(
        Account $account,
        Client $client,
        string $family,
        array $normalized,
        string $completeness = 'complete',
    ): MonitoringSnapshot {
        $fingerprint = self::fingerprint($normalized);

        $latest = MonitoringSnapshot::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('client_id', $client->id)
            ->where('family', $family)
            ->orderByDesc('version')
            ->first();

        if ($latest && $latest->fingerprint === $fingerprint) {
            $latest->forceFill(['checked_at' => now()])->save();

            return $latest->refresh();
        }

        $snapshot = MonitoringSnapshot::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'family' => $family,
            'fingerprint' => $fingerprint,
            'normalized' => $normalized,
            'version' => ($latest?->version ?? 0) + 1,
            'completeness' => $completeness,
            'checked_at' => now(),
        ]);

        // Primeira versão estabelece a base: sem mudança nem alerta.
        if ($latest !== null) {
            $change = MonitoringChange::query()->withoutGlobalScopes()->create([
                'account_id' => $account->id,
                'monitoring_snapshot_id' => $snapshot->id,
                'client_id' => $client->id,
                'family' => $family,
                'change_type' => 'updated',
                'summary' => ['from_version' => $latest->version, 'to_version' => $snapshot->version],
            ]);

            MonitoringAlert::query()->withoutGlobalScopes()->create([
                'account_id' => $account->id,
                'client_id' => $client->id,
                'family' => $family,
                'monitoring_change_id' => $change->id,
                'status' => MonitoringAlert::OPEN,
            ]);
        }

        return $snapshot;
    }

    /**
     * Reconhecimento idempotente e auditado.
     */
    public function acknowledge(MonitoringAlert $alert, User $actor): MonitoringAlert
    {
        if ($alert->isAcknowledged()) {
            return $alert;
        }

        $alert->forceFill([
            'status' => MonitoringAlert::ACKNOWLEDGED,
            'acknowledged_by' => $actor->id,
            'acknowledged_at' => now(),
        ])->save();

        $this->audit->record($actor, $alert->account_id, $alert->account_id, 'monitoring_alert.acknowledged', [
            'alert_id' => $alert->id,
            'family' => $alert->family,
        ]);

        return $alert->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function sortRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursive($value);
            }
        }

        ksort($data);

        return $data;
    }
}
