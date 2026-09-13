<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\SerproTransportGate;
use Illuminate\Http\JsonResponse;

/**
 * Effective SERPRO transport health.
 *
 * Reports the state derived from the effective gate (panel over `.env`) and
 * the Contratante credential in the vault — never from `.env` alone and never
 * from a network call. The payload carries only state and flags: no secret,
 * no PFX, no vault reference.
 *
 * Always answers 200: `gated` is the expected default of a fresh install and
 * `/up` remains the liveness endpoint. The `data.state` value drives
 * monitoring and the administration UI.
 */
class MonitoringHealthController extends Controller
{
    public function __invoke(SerproTransportGate $gate): JsonResponse
    {
        return response()->json([
            'data' => [
                'state' => $gate->transportState(),
                'environment' => $gate->environment(),
                'dry_run' => $gate->isDryRun(),
                'gated' => $gate->isGated(),
                'transport_open' => $gate->isTransportOpen(),
            ],
        ]);
    }
}
