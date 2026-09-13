<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Serpro\SetSerproTransportRequest;
use App\Http\Requests\Admin\Serpro\StoreSerproCredentialsRequest;
use App\Http\Requests\Admin\Serpro\SwitchSerproEnvironmentRequest;
use App\Models\User;
use App\Services\Monitoring\SerproAdminService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administração SERPRO da Account A (abilities via `manage-serpro`).
 *
 * Contrato fino: autorização e validação vivem nos Form Requests/policy; o
 * controller apenas delega ao serviço e devolve o overview factual, sempre
 * com identificadores mascarados e sem segredo, PFX ou senha.
 */
class SerproAdminController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request, SerproAdminService $service): JsonResponse
    {
        $this->authorize('manage-serpro');

        return response()->json(['data' => $service->overview()]);
    }

    public function storeCredentials(StoreSerproCredentialsRequest $request, SerproAdminService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        $service->replaceCredentials($actor, (string) $data['environment'], $data);

        return response()->json(['data' => $service->overview()], 201);
    }

    public function switchEnvironment(SwitchSerproEnvironmentRequest $request, SerproAdminService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        $service->switchEnvironment($actor, (string) $data['environment'], $data['evidence'] ?? null);

        return response()->json(['data' => $service->overview()]);
    }

    public function setTransport(SetSerproTransportRequest $request, SerproAdminService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        $service->setTransport($actor, (bool) $data['enabled'], $data['evidence'] ?? null);

        return response()->json(['data' => $service->overview()]);
    }
}
