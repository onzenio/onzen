<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Services\AccountCertificateService;
use App\Services\VaultService;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountCertificateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AccountCertificateService $certificates,
        private readonly VaultService $vault,
    ) {}

    private function effectiveAccountId(Request $request): int
    {
        return CurrentAccount::get() ?? $request->user()->account_id;
    }

    private function denyUnlessManager(Request $request): ?JsonResponse
    {
        if (! in_array($request->user()->role, [UserRole::SuperAdmin, UserRole::Admin], true)) {
            return response()->json(['message' => 'Gestão de Certificado restrita a admin.'], 403);
        }

        return null;
    }

    public function show(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($request)) {
            return $denied;
        }

        $cert = AccountCertificate::query()->withoutGlobalScopes()
            ->where('account_id', $this->effectiveAccountId($request))
            ->first();

        if ($cert === null) {
            return response()->json(['configured' => false]);
        }

        return response()->json(['configured' => true, ...$cert->toMaskedArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($request)) {
            return $denied;
        }

        $data = $request->validate([
            'pfx_base64' => ['required', 'string'],
            'password' => ['required', 'string', 'max:500'],
            'holder_name' => ['required', 'string', 'max:255'],
            'thumbprint' => ['required', 'string', 'max:64'],
            'expires_at' => ['required', 'date'],
        ]);

        $pfx = base64_decode($data['pfx_base64'], true);

        if ($pfx === false || $pfx === '') {
            return response()->json(['message' => 'PFX inválido.'], 422);
        }

        $account = Account::query()->findOrFail($this->effectiveAccountId($request));

        $cert = $this->certificates->register($account, [
            'pfx_ref' => $this->vault->put($account, 'pfx', $pfx),
            'password_ref' => $this->vault->put($account, 'pfx-password', $data['password']),
            'holder_name' => $data['holder_name'],
            'thumbprint' => $data['thumbprint'],
            'expires_at' => $data['expires_at'],
        ], $request->user());

        return response()->json(['configured' => true, ...$cert->toMaskedArray()], 201);
    }
}
