<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SerproRequestAuthor;
use App\Services\SerproRequestAuthorService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringAuthorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SerproRequestAuthorService $authors) {}

    private function effectiveAccountId(Request $request): int
    {
        return CurrentAccount::get() ?? $request->user()->account_id;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SerproRequestAuthor::class);

        $authors = SerproRequestAuthor::query()->withoutGlobalScopes()
            ->where('account_id', $this->effectiveAccountId($request))
            ->get();

        return response()->json([
            'data' => $authors->map(fn (SerproRequestAuthor $a) => $a->toMaskedArray()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SerproRequestAuthor::class);

        $data = $request->validate([
            'document' => ['required', 'string', 'max:18'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $account = \App\Models\Account::query()->findOrFail($this->effectiveAccountId($request));

        try {
            $author = $this->authors->register($account, $data, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }

        return response()->json($author->toMaskedArray(), 201);
    }
}
