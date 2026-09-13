<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    use AuthorizesRequests;

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Plan::class);

        $plans = Plan::query()->latest()->paginate();

        return response()->json([
            'data' => $plans->items(),
            'links' => [
                'first' => $plans->url(1),
                'last' => $plans->url($plans->lastPage()),
                'prev' => $plans->previousPageUrl(),
                'next' => $plans->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $plans->currentPage(),
                'from' => $plans->firstItem(),
                'last_page' => $plans->lastPage(),
                'per_page' => $plans->perPage(),
                'to' => $plans->lastItem(),
                'total' => $plans->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Plan::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'max_users' => ['required', 'integer', 'min:1'],
            'max_clients' => ['required', 'integer', 'min:0'],
            'modules' => ['required', 'array'],
            'modules.*' => ['string', 'max:50'],
            'monthly_query_volume' => ['required', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $plan = DB::transaction(function () use ($data) {
            if (($data['is_default'] ?? false) === true) {
                Plan::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return Plan::query()->create($data);
        });

        return response()->json($plan, 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $this->authorize('update', $plan);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'max_clients' => ['sometimes', 'integer', 'min:0'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', 'max:50'],
            'monthly_query_volume' => ['sometimes', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($plan, $data): void {
            if (($data['is_default'] ?? false) === true) {
                Plan::query()->where('is_default', true)->whereKeyNot($plan->id)->update(['is_default' => false]);
            }

            $plan->update($data);
        });

        return response()->json($plan->refresh());
    }
}
