<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MonitoringSnapshot;
use App\Services\Monitoring\SnapshotProjector;
use App\Support\MonitoringReadPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/**
 * CND da ficha do Client (Task 25): lê o snapshot vigente de Situação Fiscal
 * do acervo local, sem nenhuma chamada externa ao abrir a tela.
 *
 * O estado corrente deriva de {@see SnapshotProjector::stateFor} — freshness e
 * completude fail-closed da associação dona do snapshot. Sem snapshot, a
 * ausência é factual (`state = absent`, `cnd = null`); nunca se promete
 * cobertura nem se dispara consulta.
 */
class ClientCndController extends Controller
{
    use AuthorizesRequests;

    private const SITFIS_FAMILY = 'sitfis';

    public function __invoke(Client $client, SnapshotProjector $projector): JsonResponse
    {
        $this->authorize('view', $client);

        $snapshot = MonitoringSnapshot::query()
            ->where('client_id', $client->getKey())
            ->where('family', self::SITFIS_FAMILY)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->first();

        $clientPayload = [
            'id' => $client->id,
            'razao_social' => $client->razao_social,
            'cnpj' => $client->cnpj,
        ];

        if ($snapshot === null) {
            return response()->json([
                'data' => [
                    'client' => $clientPayload,
                    'state' => 'absent',
                    'cnd' => null,
                    'freshness' => MonitoringSnapshot::FRESHNESS_STALE,
                    'completeness' => MonitoringSnapshot::COMPLETENESS_INCOMPLETE,
                    'verified_at' => null,
                ],
            ]);
        }

        $enrollment = $snapshot->enrollment;

        $state = $enrollment === null
            ? [
                'freshness' => $snapshot->freshness,
                'completeness' => MonitoringSnapshot::COMPLETENESS_INCOMPLETE,
                'verified_at' => $snapshot->verified_at?->toIso8601String(),
            ]
            : $projector->stateFor($enrollment, $snapshot->operation_code);

        $cnd = [
            'enrollment_id' => $snapshot->enrollment_id,
            'operation_code' => $snapshot->operation_code,
            'family' => $snapshot->family,
            'normalized' => (bool) $snapshot->normalized,
            'fingerprint' => $snapshot->fingerprint,
            'verified_at' => $snapshot->verified_at?->toIso8601String(),
        ];

        if ($snapshot->normalized) {
            $cnd['data'] = MonitoringReadPayload::sanitize($snapshot->data);
        }

        return response()->json([
            'data' => [
                'client' => $clientPayload,
                'state' => 'available',
                'cnd' => $cnd,
                'freshness' => $state['freshness'],
                'completeness' => $state['completeness'],
                'verified_at' => $state['verified_at'] ?? $snapshot->verified_at?->toIso8601String(),
            ],
        ]);
    }
}
