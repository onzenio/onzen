<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwitchTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:3000';

    /**
     * @return array{cookies: array<string, string>, xsrf: string}
     */
    private function loginAndCaptureCookies(string $email, string $password = 'password'): array
    {
        $login = $this->postJson('/login', ['email' => $email, 'password' => $password]);
        $login->assertOk();

        $cookies = [];
        $xsrf = null;

        foreach ($login->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();

            if ($cookie->getName() === 'XSRF-TOKEN') {
                $xsrf = $cookie->getValue();
            }
        }

        $this->assertNotNull($xsrf, 'Resposta de login não emitiu o cookie XSRF-TOKEN.');
        $this->assertArrayHasKey(config('session.cookie'), $cookies, 'Resposta de login não emitiu o cookie de sessão.');

        return ['cookies' => $cookies, 'xsrf' => $xsrf];
    }

    /**
     * @param  array{cookies: array<string, string>, xsrf: string}  $jar
     */
    private function withSessionJar(array $jar): static
    {
        return $this->withCredentials()->withUnencryptedCookies($jar['cookies'])->withHeaders([
            'X-XSRF-TOKEN' => $jar['xsrf'],
            'Origin' => self::FRONTEND_ORIGIN,
        ]);
    }

    public function test_super_admin_enters_switch_with_real_cookie_session(): void
    {
        $home = $this->createAccount();
        $admin = $this->createUser($home, ['role' => UserRole::SuperAdmin]);
        $target = $this->createAccount();
        $jar = $this->loginAndCaptureCookies($admin->email);

        $this->withSessionJar($jar)->postJson('/api/switch', ['account_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('acting_as.account_id', $target->id)
            ->assertJsonPath('account.id', $target->id);

        $this->withSessionJar($jar)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('account.id', $target->id)
            ->assertJsonPath('acting_as.account_id', $target->id)
            ->assertJsonPath('user.id', $admin->id);

        // Vínculo preservado: o User continua na Account de origem.
        $this->assertSame($home->id, $admin->refresh()->account_id);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'account_id' => $home->id]);
    }

    public function test_switched_super_admin_sees_target_account_data(): void
    {
        $home = $this->createAccount();
        $admin = $this->createUser($home, ['role' => UserRole::SuperAdmin]);
        $target = $this->createAccount();

        $foreign = Client::factory()->for($target, 'account')->create(['razao_social' => 'Alvo SA']);
        Client::factory()->for($home, 'account')->create(['razao_social' => 'Origem SA']);

        $this->actingAs($admin)->withSession(['switch_account_id' => $target->id])
            ->withHeaders(['Origin' => self::FRONTEND_ORIGIN])
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment(['razao_social' => 'Alvo SA'])
            ->assertJsonMissing(['razao_social' => 'Origem SA']);

        $this->assertDatabaseMissing('clients', ['id' => $foreign->id, 'account_id' => $home->id]);
    }

    public function test_super_admin_exits_switch_with_real_cookie_session(): void
    {
        $home = $this->createAccount();
        $admin = $this->createUser($home, ['role' => UserRole::SuperAdmin]);
        $target = $this->createAccount();
        $jar = $this->loginAndCaptureCookies($admin->email);

        $this->withSessionJar($jar)->postJson('/api/switch', ['account_id' => $target->id])->assertOk();
        $this->withSessionJar($jar)->getJson('/api/me')->assertJsonPath('account.id', $target->id);

        $this->withSessionJar($jar)->deleteJson('/api/switch')
            ->assertOk()
            ->assertJsonPath('account.id', $home->id)
            ->assertJsonPath('acting_as', null);

        $this->withSessionJar($jar)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('account.id', $home->id)
            ->assertJsonPath('acting_as', null);
    }

    public function test_switch_to_unknown_or_missing_account_returns_422(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->withHeaders(['Origin' => self::FRONTEND_ORIGIN])
            ->postJson('/api/switch', ['account_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);

        $this->actingAs($admin)->withHeaders(['Origin' => self::FRONTEND_ORIGIN])
            ->postJson('/api/switch', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_non_super_admin_cannot_switch_and_session_is_ignored(): void
    {
        $target = $this->createAccount();

        foreach ([UserRole::Admin, UserRole::Operator, UserRole::User] as $role) {
            $user = $this->createUser(attributes: ['role' => $role]);

            $this->actingAs($user)->withHeaders(['Origin' => self::FRONTEND_ORIGIN])
                ->postJson('/api/switch', ['account_id' => $target->id])
                ->assertForbidden();

            $this->actingAs($user)->withHeaders(['Origin' => self::FRONTEND_ORIGIN])
                ->deleteJson('/api/switch')
                ->assertForbidden();

            // Mesmo com switch_account_id semeado, a sessão é ignorada.
            $this->actingAs($user)->withSession(['switch_account_id' => $target->id])
                ->withHeaders(['Origin' => self::FRONTEND_ORIGIN])
                ->getJson('/api/me')
                ->assertOk()
                ->assertJsonPath('account.id', $user->account_id)
                ->assertJsonPath('acting_as', null);
        }
    }

    public function test_guest_cannot_switch(): void
    {
        $target = $this->createAccount();

        $this->postJson('/api/switch', ['account_id' => $target->id])->assertUnauthorized();
        $this->deleteJson('/api/switch')->assertUnauthorized();
    }

    public function test_switch_enter_and_exit_are_audited(): void
    {
        $home = $this->createAccount();
        $admin = $this->createUser($home, ['role' => UserRole::SuperAdmin]);
        $target = $this->createAccount();
        $jar = $this->loginAndCaptureCookies($admin->email);

        $this->withSessionJar($jar)->postJson('/api/switch', ['account_id' => $target->id])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'origin_account_id' => $home->id,
            'target_account_id' => $target->id,
            'action' => 'account.switch.enter',
        ]);

        $this->withSessionJar($jar)->deleteJson('/api/switch')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'origin_account_id' => $home->id,
            'target_account_id' => $target->id,
            'action' => 'account.switch.exit',
        ]);
    }
}
