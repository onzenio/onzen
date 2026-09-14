<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SerproContract;
use App\Services\AuditService;
use App\Services\MonitoringHealthService;
use App\Services\SerproContractService;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SerproAdminController extends Controller
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly VaultService $vault,
        private readonly MonitoringHealthService $health,
        private readonly AuditService $audit,
    ) {}

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['message' => 'Acesso restrito à administração da plataforma.'], 403);
        }

        return null;
    }

    private function platformAccount(Request $request): Account
    {
        return Account::query()->where('profile', 'A')->first()
            ?? $request->user()->account;
    }

    public function show(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $contract = $this->contracts->getOrCreate();
        $platform = $this->platformAccount($request);

        return response()->json([
            ...$contract->toMaskedArray(),
            'dry_run' => (bool) config('monitoring.dry_run', true),
            'health' => $this->health->check($platform),
        ]);
    }

    public function updateCredentials(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $data = $request->validate([
            'consumer_key' => ['required', 'string', 'min:1', 'max:255'],
            'consumer_secret' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $platform = $this->platformAccount($request);
        $contract = $this->contracts->getOrCreate();

        $keyRef = $this->vault->put($platform, 'consumer-key', $data['consumer_key']);
        $secretRef = $this->vault->put($platform, 'consumer-secret', $data['consumer_secret']);

        $this->contracts->rotateCredentials($contract, $keyRef, $secretRef, $request->user());

        return response()->json($contract->refresh()->toMaskedArray());
    }

    public function switchEnvironment(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $data = $request->validate([
            'environment' => ['required', 'in:homologacao,producao'],
            'confirmed' => ['required', 'boolean'],
            'evidence' => ['nullable', 'string', 'max:2000'],
        ]);

        $contract = $this->contracts->getOrCreate();
        $actor = $request->user();

        $needsEvidence = $data['environment'] === SerproContract::ENV_PRODUCAO;

        if (! $data['confirmed'] || ($needsEvidence && empty($data['evidence']))) {
            $this->audit->record($actor, $actor->account_id, null, 'serpro_contract.environment_switch_refused', [
                'from' => $contract->environment,
                'to' => $data['environment'],
            ]);

            return response()->json([
                'message' => 'Alternância para produção exige dupla confirmação com evidência.',
            ], 422);
        }

        $from = $contract->environment;
        $contract->forceFill([
            'environment' => $data['environment'],
            // Fail-closed: ambiente novo começa com transporte desligado.
            'transport_approved' => $from === $data['environment'] ? $contract->transport_approved : false,
        ])->save();

        $this->audit->record($actor, $actor->account_id, null, 'serpro_contract.environment_switched', [
            'from' => $from,
            'to' => $data['environment'],
            'evidence' => $needsEvidence ? true : false,
        ]);

        return response()->json($contract->refresh()->toMaskedArray());
    }

    public function switchTransport(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessSuperAdmin($request)) {
            return $denied;
        }

        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'confirmed' => ['required', 'boolean'],
            'evidence' => ['nullable', 'string', 'max:2000'],
        ]);

        $contract = $this->contracts->getOrCreate();
        $actor = $request->user();

        // Desligar é imediato: sem evidência, sem espera.
        if (! $data['approved']) {
            $contract->forceFill(['transport_approved' => false])->save();

            $this->audit->record($actor, $actor->account_id, null, 'serpro_transport.disabled', [
                'environment' => $contract->environment,
            ]);

            return response()->json($contract->refresh()->toMaskedArray());
        }

        $needsEvidence = $contract->environment === SerproContract::ENV_PRODUCAO;

        if (! $data['confirmed'] || ($needsEvidence && empty($data['evidence']))) {
            $this->audit->record($actor, $actor->account_id, null, 'serpro_transport.enable_refused', [
                'environment' => $contract->environment,
            ]);

            return response()->json([
                'message' => 'Religar o transporte em produção exige dupla confirmação com evidência.',
            ], 422);
        }

        $contract->forceFill(['transport_approved' => true])->save();

        $this->audit->record($actor, $actor->account_id, null, 'serpro_transport.enabled', [
            'environment' => $contract->environment,
        ]);

        return response()->json($contract->refresh()->toMaskedArray());
    }
}
