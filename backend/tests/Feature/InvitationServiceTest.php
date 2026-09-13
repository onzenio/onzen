<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Plan;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_creates_pending_invitation_and_sends_mail(): void
    {
        Mail::fake();

        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $inviter = $this->createUser($account, ['role' => UserRole::Admin]);

        $invitation = app(InvitationService::class)->invite($account, $inviter, [
            'name' => 'Convidada',
            'email' => 'convidada@example.com',
            'role' => 'operator',
        ]);

        $this->assertSame(64, strlen($invitation->token));
        $this->assertSame(hash('sha256', $invitation->token), $invitation->token_hash);
        $this->assertNotEquals($invitation->token, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $invitation->expires_at->timestamp, 120);
        $this->assertNull($invitation->accepted_at);
        $this->assertSame($account->id, $invitation->account_id);
        $this->assertSame($inviter->id, $invitation->invited_by_user_id);
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'token_hash' => hash('sha256', $invitation->token),
        ]);

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($invitation) {
            return $mail->hasTo('convidada@example.com') && $mail->rawToken === $invitation->token;
        });
    }

    public function test_invite_denies_email_already_on_platform(): void
    {
        Mail::fake();

        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $inviter = $this->createUser($account, ['role' => UserRole::Admin]);
        $existing = $this->createUser(attributes: ['email' => 'alguem@example.com']);

        $this->assertNotSame($existing->account_id, $account->id);

        $this->expectException(ValidationException::class);

        app(InvitationService::class)->invite($account, $inviter, [
            'name' => 'Duplicada',
            'email' => 'alguem@example.com',
            'role' => 'user',
        ]);
    }

    public function test_invite_denies_duplicate_pending_invitation(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $inviter = $this->createUser($account, ['role' => UserRole::Admin]);

        Invitation::factory()->create([
            'account_id' => $account->id,
            'email' => 'pendente@example.com',
            'invited_by_user_id' => $inviter->id,
        ]);

        $this->expectException(ValidationException::class);

        app(InvitationService::class)->invite($account, $inviter, [
            'name' => 'Outra',
            'email' => 'pendente@example.com',
            'role' => 'user',
        ]);
    }

    public function test_invite_denies_super_admin_and_invalid_roles(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $inviter = $this->createUser($account, ['role' => UserRole::Admin]);
        $service = app(InvitationService::class);

        foreach (['super_admin', 'owner', ''] as $role) {
            try {
                $service->invite($account, $inviter, [
                    'name' => 'Nome',
                    'email' => "role-{$role}-x@example.com",
                    'role' => $role,
                ]);
                $this->fail("Role [{$role}] deveria ser negado.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_invite_denies_when_plan_limit_reached(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 1])->id]);
        $inviter = $this->createUser($account, ['role' => UserRole::Admin]);

        $this->expectException(ValidationException::class);

        app(InvitationService::class)->invite($account, $inviter, [
            'name' => 'Sem Vaga',
            'email' => 'semvaga@example.com',
            'role' => 'user',
        ]);
    }

    public function test_accept_creates_user_with_invite_role_and_hashed_password(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $inviter = $this->createUser($account, ['role' => UserRole::Admin]);
        $invitation = Invitation::factory()->create([
            'account_id' => $account->id,
            'role' => UserRole::Operator,
            'invited_by_user_id' => $inviter->id,
        ]);

        $user = app(InvitationService::class)->accept($invitation->token, 'secret123');

        $this->assertSame($account->id, $user->account_id);
        $this->assertSame(UserRole::Operator, $user->role);
        $this->assertSame($invitation->email, $user->email);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertNotNull($invitation->refresh()->accepted_at);
    }

    public function test_accept_denies_expired_invitation(): void
    {
        $invitation = Invitation::factory()->create([
            'expires_at' => now()->subDay(),
            'accepted_at' => null,
        ]);

        $this->expectException(ValidationException::class);

        app(InvitationService::class)->accept($invitation->token, 'secret123');
    }

    public function test_accept_denies_already_used_invitation(): void
    {
        $invitation = Invitation::factory()->create(['accepted_at' => now()]);

        $this->expectException(ValidationException::class);

        app(InvitationService::class)->accept($invitation->token, 'secret123');
    }

    public function test_accept_denies_revoked_invitation(): void
    {
        $invitation = Invitation::factory()->create(['accepted_at' => null]);
        $token = $invitation->token;

        app(InvitationService::class)->revoke($invitation);

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
        $this->expectException(ValidationException::class);

        app(InvitationService::class)->accept($token, 'secret123');
    }

    public function test_accept_denies_unknown_token(): void
    {
        $this->expectException(ValidationException::class);

        app(InvitationService::class)->accept(str_repeat('a', 64), 'secret123');
    }

    public function test_accept_denies_when_plan_limit_reached(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 2])->id]);
        $inviter = $this->createUser($account, ['role' => UserRole::Admin]);
        $invitation = Invitation::factory()->create([
            'account_id' => $account->id,
            'invited_by_user_id' => $inviter->id,
        ]);
        $this->createUser($account);

        $this->expectException(ValidationException::class);

        app(InvitationService::class)->accept($invitation->token, 'secret123');
    }
}
