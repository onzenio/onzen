<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Client;
use App\Rules\Cnpj;
use App\Services\PlanLimitService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.mb_strtolower((string) $request->input('search')).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->whereRaw('LOWER(razao_social) LIKE ?', [$term])
                        ->orWhere('cnpj', 'like', $term);
                });
            })
            ->when($request->filled('regime'), fn ($query) => $query->where('regime', $request->input('regime')))
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $clients->items(),
            'links' => [
                'first' => $clients->url(1),
                'last' => $clients->url($clients->lastPage()),
                'prev' => $clients->previousPageUrl(),
                'next' => $clients->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $clients->currentPage(),
                'from' => $clients->firstItem(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'to' => $clients->lastItem(),
                'total' => $clients->total(),
            ],
        ]);
    }

    public function store(Request $request, PlanLimitService $limits): JsonResponse
    {
        $this->authorize('create', Client::class);

        $accountId = CurrentAccount::get() ?? $request->user()->account_id;

        $data = $request->validate([
            'cnpj' => ['required', 'string', 'regex:/^\d{14}$/', new Cnpj, $this->uniqueCnpj($accountId)],
            'razao_social' => ['required', 'string', 'max:255'],
            'regime' => ['required', 'string', 'max:50'],
            'contador_responsavel' => ['required', 'string', 'max:255'],
        ]);

        $account = Account::query()->findOrFail($accountId)->load('plan');

        if ($message = $limits->canCreateClient($account)) {
            throw ValidationException::withMessages(['cnpj' => [$message]]);
        }

        $client = Client::query()->create(array_merge($data, ['account_id' => $account->id]));

        return response()->json($client->refresh(), 201);
    }

    public function show(string $id): JsonResponse
    {
        // Busca manual escopada: a substituição implícita de modelos roda
        // antes do ResolveAccount, então o global scope ainda não vale no
        // binding — aqui o CurrentAccount já está resolvido e outra Account
        // resulta em 404 (sem leak), nunca 403.
        $client = Client::query()->whereKey($id)->firstOrFail();

        $this->authorize('view', $client);

        return response()->json($client);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $client = Client::query()->whereKey($id)->firstOrFail();

        $this->authorize('update', $client);

        $data = $request->validate([
            'cnpj' => ['sometimes', 'string', 'regex:/^\d{14}$/', new Cnpj, $this->uniqueCnpj($client->account_id, $client->id)],
            'razao_social' => ['sometimes', 'string', 'max:255'],
            'regime' => ['sometimes', 'string', 'max:50'],
            'contador_responsavel' => ['sometimes', 'string', 'max:255'],
        ]);

        $client->update($data);

        return response()->json($client->refresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $client = Client::query()->whereKey($id)->firstOrFail();

        $this->authorize('delete', $client);

        $client->delete();

        return response()->json(null, 204);
    }

    public function updateMonitoring(Request $request, string $id): JsonResponse
    {
        $client = Client::query()->whereKey($id)->firstOrFail();

        $this->authorize('update', $client);

        $data = $request->validate([
            'monitoring_enabled' => ['required', 'boolean'],
        ]);

        $client->update($data);

        return response()->json($client->refresh());
    }

    protected function uniqueCnpj(int $accountId, ?int $exceptId = null): Unique
    {
        return Rule::unique('clients', 'cnpj')
            ->where(fn ($query) => $query->where('account_id', $accountId))
            ->when($exceptId !== null, fn ($rule) => $rule->ignore($exceptId));
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 15)));
    }
}
