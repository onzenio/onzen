<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_only_own_account_invitations(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $mine = Invitation::factory()->create(['account_id' => $account->id]);
        $foreign = Invitation::factory()->create();

        $this->actingAs($admin)->getJson('/api/invitations')
            ->assertOk()
            ->assertJsonFragment(['id' => $mine->id])
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_admin_creates_invitation_and_mail_is_sent(): void
    {
        Mail::fake();
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->postJson('/api/invitations', [
            'name' => 'Novo',
            'email' => 'novo@example.com',
            'role' => 'operator',
        ]);

        $response->assertCreated()->assertJsonPath('email', 'novo@example.com');
        $this->assertArrayNotHasKey('token_hash', $response->json());
        $this->assertDatabaseHas('invitations', ['email' => 'novo@example.com', 'account_id' => $account->id]);
        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('novo@example.com'));
    }

    public function test_create_rejects_super_admin_role_and_duplicates(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        Invitation::factory()->create(['account_id' => $account->id, 'email' => 'dup@example.com']);

        $this->actingAs($admin)->postJson('/api/invitations', [
            'name' => 'X', 'email' => 'x@example.com', 'role' => 'super_admin',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/invitations', [
            'name' => 'Y', 'email' => 'dup@example.com', 'role' => 'user',
        ])->assertStatus(422);
    }

    public function test_operator_cannot_manage_invitations_and_guest_is_unauthorized(): void
    {
        $this->getJson('/api/invitations')->assertUnauthorized();

        $operator = $this->createUser(attributes: ['role' => UserRole::Operator]);

        $this->actingAs($operator)->getJson('/api/invitations')->assertForbidden();
        $this->actingAs($operator)->postJson('/api/invitations', [
            'name' => 'X', 'email' => 'x@example.com', 'role' => 'user',
        ])->assertForbidden();
    }

    public function test_accept_valid_invitation_creates_user_and_authenticates(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $invitation = Invitation::factory()->create([
            'account_id' => $account->id,
            'role' => UserRole::Operator,
        ]);

        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->postJson("/api/invitations/{$invitation->token}/accept", ['password' => 'secret123']);

        $response->assertCreated()->assertJsonPath('user.email', $invitation->email);

        $this->assertDatabaseHas('users', [
            'email' => $invitation->email,
            'account_id' => $account->id,
            'role' => UserRole::Operator->value,
        ]);
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertAuthenticated();
    }

    public function test_accept_rejects_expired_used_and_unknown_tokens(): void
    {
        $expired = Invitation::factory()->create(['expires_at' => now()->subDay()]);
        $used = Invitation::factory()->create(['accepted_at' => now()]);

        $this->postJson("/api/invitations/{$expired->token}/accept", ['password' => 'secret123'])->assertStatus(422);
        $this->postJson("/api/invitations/{$used->token}/accept", ['password' => 'secret123'])->assertStatus(422);
        $this->postJson('/api/invitations/'.str_repeat('b', 64).'/accept', ['password' => 'secret123'])->assertStatus(422);
        $this->postJson("/api/invitations/{$expired->token}/accept", ['password' => 'short'])->assertStatus(422);
    }

    public function test_admin_revokes_invitation_and_accept_stops_working(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $invitation = Invitation::factory()->create(['account_id' => $account->id]);
        $token = $invitation->token;

        $this->actingAs($admin)->deleteJson("/api/invitations/{$invitation->id}")->assertNoContent();
        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);

        $this->postJson("/api/invitations/{$token}/accept", ['password' => 'secret123'])->assertStatus(422);
    }

    public function test_admin_cannot_revoke_invitation_from_another_account(): void
    {
        $account = $this->createAccount(['plan_id' => Plan::factory()->create(['max_users' => 10])->id]);
        $admin = $this->createUser($account, ['role' => UserRole::Admin]);
        $foreign = Invitation::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/invitations/{$foreign->id}")->assertNotFound();
    }
}
