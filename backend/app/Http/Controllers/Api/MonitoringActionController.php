<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SerproServiceRequest;
use App\Services\ActionService;
use App\Support\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MonitoringActionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ActionService $actions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'kind' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'confirmed' => ['required', 'boolean'],
        ]);

        $accountId = CurrentAccount::get() ?? $request->user()->account_id;
        $account = Account::query()->findOrFail($accountId);

        try {
            $serviceRequest = $this->actions->requestEmission(
                $account,
                $request->user(),
                $data['client_id'],
                $data['kind'],
                $data['idempotency_key'],
                $data['confirmed'],
            );
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Client não encontrado.'], 404);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return response()->json($serviceRequest, 202);
    }

    public function poll(Request $request, int $id): JsonResponse
    {
        $accountId = CurrentAccount::get() ?? $request->user()->account_id;

        $serviceRequest = SerproServiceRequest::query()->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->whereKey($id)
            ->firstOrFail();

        return response()->json($this->actions->pollProtocol($serviceRequest));
    }
}
