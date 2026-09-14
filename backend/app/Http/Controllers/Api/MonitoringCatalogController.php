<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class MonitoringCatalogController extends Controller
{
    use AuthorizesRequests;

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MonitoringEnrollment::class);

        return response()->json([
            'version' => \Database\Seeders\MonitoringDefinitionSeeder::CATALOG_VERSION,
            'data' => MonitoringDefinition::query()->orderBy('name')->get([
                'code', 'family', 'name', 'availability', 'strategy',
                'person_types', 'regimes', 'automatic', 'unavailability_reason',
            ]),
            'procuration_allowlist' => \Database\Seeders\MonitoringDefinitionSeeder::procurationAllowlist(),
        ]);
    }
}
