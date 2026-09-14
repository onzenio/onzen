<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AccountCertificateService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param array{pfx_ref: string, password_ref: string, holder_name: string, thumbprint: string, expires_at: mixed} $data
     */
    public function register(Account $account, array $data, ?User $actor = null): AccountCertificate
    {
        if (empty($data['pfx_ref']) || empty($data['password_ref'])) {
            throw ValidationException::withMessages([
                'pfx_ref' => 'PFX inválido ou senha não confere.',
            ]);
        }

        $existing = AccountCertificate::query()->withoutGlobalScopes()->where('account_id', $account->id)->first();

        if ($existing) {
            $existing->forceFill([
                'pfx_ref' => $data['pfx_ref'],
                'password_ref' => $data['password_ref'],
                'holder_name' => $data['holder_name'],
                'thumbprint' => $data['thumbprint'],
                'expires_at' => $data['expires_at'],
            ])->save();

            $this->audit->record($actor, $account->id, $account->id, 'account_certificate.replaced', [
                'thumbprint' => $data['thumbprint'],
                'holder_name' => $data['holder_name'],
            ]);

            return $existing->refresh();
        }

        $cert = AccountCertificate::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            ...$data,
        ]);

        $this->audit->record($actor, $account->id, $account->id, 'account_certificate.registered', [
            'thumbprint' => $data['thumbprint'],
            'holder_name' => $data['holder_name'],
        ]);

        return $cert;
    }
}
