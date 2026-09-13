<?php

namespace App\Services;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountProvisioningService
{
    public function __construct(protected InvitationService $invitations) {}

    /**
     * @return array{account: Account, invitation: Invitation, token: string}
     */
    public function provision(
        string $accountName,
        string $adminEmail,
        string $adminName,
        ?User $inviter = null,
    ): array {
        return DB::transaction(function () use ($accountName, $adminEmail, $adminName, $inviter) {
            $account = Account::query()->create([
                'name' => $accountName,
                'profile' => AccountProfile::B,
                'plan_id' => Plan::default()?->id,
            ]);

            $invitation = $this->invitations->invite($account, $inviter, [
                'name' => $adminName,
                'email' => $adminEmail,
                'role' => UserRole::Admin->value,
            ]);

            return [
                'account' => $account,
                'invitation' => $invitation,
                'token' => $invitation->token,
            ];
        });
    }
}
