<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'account_id' => ['sometimes', 'integer', 'min:1'],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = AuditLog::query()->with('actor:id,name,email')->latest('id');

        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            $effective = CurrentAccount::get() ?? $user->account_id;
            $query->where(function ($inner) use ($effective): void {
                $inner->where('origin_account_id', $effective)
                    ->orWhere('target_account_id', $effective);
            });
        }

        if (isset($filters['account_id'])) {
            $id = (int) $filters['account_id'];
            $query->where(function ($inner) use ($id): void {
                $inner->where('origin_account_id', $id)
                    ->orWhere('target_account_id', $id);
            });
        }

        if (isset($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $logs = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => $logs->items(),
            'links' => [
                'first' => $logs->url(1),
                'last' => $logs->url($logs->lastPage()),
                'prev' => $logs->previousPageUrl(),
                'next' => $logs->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $logs->currentPage(),
                'from' => $logs->firstItem(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ],
        ]);
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 15)));
    }
}
