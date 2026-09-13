<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Plan;
use App\Services\AccountProvisioningService;
use App\Services\InvitationService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_creates_account_b_with_default_plan_and_admin_invite(): void
    {
        Mail::fake();
        $this->seed(PlanSeeder::class);
        $provisioner = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        ['account' => $account, 'invitation' => $invitation, 'token' => $token] =
            app(AccountProvisioningService::class)->provision('Escritório B', 'admin-b@example.com', 'Admin B', $provisioner);

        $this->assertSame(AccountProfile::B, $account->profile);
        $this->assertSame('Escritório B', $account->name);
        $this->assertSame(Plan::default()->id, $account->plan_id);
        $this->assertSame($account->id, $invitation->account_id);
        $this->assertSame(UserRole::Admin, $invitation->role);
        $this->assertSame('admin-b@example.com', $invitation->email);
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertSame($provisioner->id, $invitation->invited_by_user_id);
    }

    public function test_provision_is_transactional_on_invite_failure(): void
    {
        Mail::fake();
        $this->seed(PlanSeeder::class);
        $provisioner = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
        $existing = $this->createUser(attributes: ['email' => 'taken@example.com']);

        try {
            app(AccountProvisioningService::class)->provision('Escritório C', 'taken@example.com', 'Admin C', $provisioner);
            $this->fail('Provisionamento com e-mail existente deveria falhar.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(2, Account::query()->count());
        $this->assertDatabaseMissing('accounts', ['name' => 'Escritório C']);
        $this->assertDatabaseMissing('invitations', ['email' => 'taken@example.com', 'accepted_at' => null]);
    }

    public function test_failed_transaction_after_invite_sends_no_mail_and_leaves_no_orphan_account(): void
    {
        Mail::fake();
        $this->seed(PlanSeeder::class);
        $provisioner = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        try {
            DB::transaction(function () use ($provisioner) {
                $account = Account::query()->create([
                    'name' => 'Escritório Órfão',
                    'profile' => AccountProfile::B,
                    'plan_id' => Plan::default()?->id,
                ]);

                app(InvitationService::class)->invite($account, $provisioner, [
                    'name' => 'Órfã',
                    'email' => 'orfa@example.com',
                    'role' => 'admin',
                ]);

                throw new \RuntimeException('forced post-create failure');
            });
            $this->fail('Falha forçada pós-create deveria reverter a transação.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced post-create failure', $e->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('accounts', ['name' => 'Escritório Órfão']);
        $this->assertDatabaseMissing('invitations', ['email' => 'orfa@example.com']);
    }
}
