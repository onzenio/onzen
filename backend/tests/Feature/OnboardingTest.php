<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_reports_available_on_empty_database(): void
    {
        $this->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJsonPath('available', true);
    }

    public function test_onboarding_creates_account_a_with_super_admin_and_authenticates(): void
    {
        // Origin stateful (como o BFF): abre sessão stateful no grupo api,
        // então o login emite cookies de sessão como no contrato da fase C.
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])->postJson('/api/onboarding', [
            'name' => 'Fundadora',
            'email' => 'fundadora@example.com',
            'password' => 'secret123',
            'account_name' => 'Matriz',
        ]);

        $response->assertCreated();

        $account = Account::query()->firstOrFail();
        $this->assertSame(AccountProfile::A, $account->profile);
        $this->assertSame('Matriz', $account->name);

        $this->assertDatabaseHas('users', [
            'email' => 'fundadora@example.com',
            'account_id' => $account->id,
            'role' => UserRole::SuperAdmin->value,
        ]);

        $this->assertAuthenticated();

        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->assertArrayHasKey(config('session.cookie'), $cookies);

        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();

        $this->withCredentials()->withUnencryptedCookies($cookies)
            ->withHeaders(['Origin' => 'http://localhost:3000'])
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('account.id', $account->id)
            ->assertJsonPath('user.role', UserRole::SuperAdmin->value);
    }

    public function test_onboarding_is_unavailable_once_database_is_populated(): void
    {
        $this->createUser();

        $this->postJson('/api/onboarding', [
            'name' => 'Outra',
            'email' => 'outra@example.com',
            'password' => 'secret123',
            'account_name' => 'Filial',
        ])->assertConflict();

        $this->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJsonPath('available', false);
    }

    public function test_onboarding_validates_input(): void
    {
        $this->postJson('/api/onboarding', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'account_name']);
    }
}
