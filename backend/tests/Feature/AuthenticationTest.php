<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_disabled(): void
    {
        $this->postJson('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->getJson('/register')->assertNotFound();
    }

    public function test_profile_password_two_factor_and_passkey_routes_are_disabled(): void
    {
        $user = $this->createUser(attributes: ['email' => 'auth-disabled@example.com']);

        // Headless puro: sem view de login em HTML (só POST /login existe).
        $this->getJson('/login')->assertStatus(405);

        $this->actingAs($user)->putJson('/user/profile-information', [
            'name' => 'Changed',
            'email' => 'auth-disabled@example.com',
        ])->assertNotFound();

        $this->actingAs($user)->putJson('/user/password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertNotFound();

        $this->actingAs($user)->postJson('/user/two-factor-authentication')->assertNotFound();

        $this->actingAs($user)->postJson('/user/passkeys', [])->assertNotFound();
    }

    public function test_login_with_valid_credentials_returns_200_and_authenticates(): void
    {
        $user = $this->createUser(attributes: ['email' => 'auth-valid@example.com']);

        $this->postJson('/login', [
            'email' => 'auth-valid@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('two_factor', false);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_invalid_credentials_returns_422_without_revealing_existence(): void
    {
        $this->createUser(attributes: ['email' => 'auth-invalid@example.com']);

        $wrongPassword = $this->postJson('/login', [
            'email' => 'auth-invalid@example.com',
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->postJson('/login', [
            'email' => 'nobody-here@example.com',
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertUnprocessable()->assertJsonValidationErrors('email');
        $unknownEmail->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame($wrongPassword->json('errors.email'), $unknownEmail->json('errors.email'));

        $this->assertGuest();
    }

    public function test_logout_returns_204_and_revokes_session(): void
    {
        $user = $this->createUser(attributes: ['email' => 'auth-logout@example.com']);

        $this->actingAs($user)->postJson('/logout')->assertNoContent();

        $this->assertGuest();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_password_reset_link_is_sent_for_known_email(): void
    {
        Notification::fake();

        $user = $this->createUser(attributes: ['email' => 'auth-reset@example.com']);

        $this->postJson('/forgot-password', [
            'email' => 'auth-reset@example.com',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_valid_token_and_sessions_are_invalidated(): void
    {
        Notification::fake();

        $user = $this->createUser(attributes: ['email' => 'auth-reset-valid@example.com']);
        $rememberBefore = $user->remember_token;

        // Prova de invalidação de sessões: em produção SESSION_DRIVER=database,
        // então as linhas em `sessions` SÃO as sessões vivas. Semeia uma sessão
        // pré-reset do usuário + uma linha de controle de outro usuário
        // (nos testes o driver é array, mas a tabela existe via RefreshDatabase).
        $otherUser = $this->createUser(attributes: ['email' => 'auth-reset-other@example.com']);
        DB::table('sessions')->insert([
            'id' => 'pre-reset-session-id',
            'user_id' => $user->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'test',
            'last_activity' => now()->getTimestamp(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'other-user-session-id',
            'user_id' => $otherUser->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'test',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'auth-reset-valid@example.com',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotEmpty($token);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'auth-reset-valid@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();

        $user->refresh();

        $this->assertTrue(Hash::check('new-secure-password', $user->password));
        $this->assertNotSame($rememberBefore, $user->remember_token);

        // Sessões pré-reset do usuário morrem; as de outros usuários sobrevivem.
        $this->assertDatabaseMissing('sessions', ['id' => 'pre-reset-session-id']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session-id']);

        $this->postJson('/login', [
            'email' => 'auth-reset-valid@example.com',
            'password' => 'password',
        ])->assertUnprocessable();

        $this->postJson('/login', [
            'email' => 'auth-reset-valid@example.com',
            'password' => 'new-secure-password',
        ])->assertOk();
    }

    public function test_password_reset_with_invalid_token_returns_422(): void
    {
        $this->createUser(attributes: ['email' => 'auth-reset-bad@example.com']);

        $this->postJson('/reset-password', [
            'token' => 'invalid-token',
            'email' => 'auth-reset-bad@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_password_reset_with_expired_token_returns_422(): void
    {
        Notification::fake();

        $user = $this->createUser(attributes: ['email' => 'auth-reset-expired@example.com']);

        $this->postJson('/forgot-password', [
            'email' => 'auth-reset-expired@example.com',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        DB::table('password_reset_tokens')
            ->where('email', 'auth-reset-expired@example.com')
            ->update(['created_at' => now()->subHours(2)]);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'auth-reset-expired@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        $this->createUser(attributes: ['email' => 'auth-throttle@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'email' => 'auth-throttle@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/login', [
            'email' => 'auth-throttle@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
