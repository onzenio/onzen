<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionContractTest extends TestCase
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
                // Headers não são decodificados: o X-XSRF-TOKEN usa o valor cru.
                $xsrf = $cookie->getValue();
            }
        }

        $this->assertNotNull($xsrf, 'Resposta de login não emitiu o cookie XSRF-TOKEN.');
        $this->assertArrayHasKey(config('session.cookie'), $cookies, 'Resposta de login não emitiu o cookie de sessão.');

        return ['cookies' => $cookies, 'xsrf' => $xsrf];
    }

    /**
     * Reenvia o jar como um browser faria: cookies já cifrados vão crus
     * (withUnencryptedCookies) + header X-XSRF-TOKEN, como o BFF fará.
     *
     * @param  array{cookies: array<string, string>, xsrf: string}  $jar
     */
    private function withSessionJar(array $jar, ?string $xsrfOverride = null): static
    {
        // withCredentials: sem ele, as chamadas *Json nunca enviam cookies.
        return $this->withCredentials()->withUnencryptedCookies($jar['cookies'])->withHeaders([
            'X-XSRF-TOKEN' => $xsrfOverride ?? $jar['xsrf'],
            'Origin' => self::FRONTEND_ORIGIN,
        ]);
    }

    /**
     * Simula um processo novo: descarta guards resolvidos e esvazia a sessão
     * em memória para provar que a autenticação vem do cookie, não de
     * estado ambiente do teste.
     */
    private function simulateFreshProcess(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
    }

    public function test_login_issues_session_and_xsrf_cookies(): void
    {
        $this->createUser(attributes: ['email' => 'session-issue@example.com']);

        $login = $this->postJson('/login', [
            'email' => 'session-issue@example.com',
            'password' => 'password',
        ]);
        $login->assertOk();

        $names = collect($login->headers->getCookies())->map(fn ($cookie) => $cookie->getName())->all();

        $this->assertContains(config('session.cookie'), $names);
        $this->assertContains('XSRF-TOKEN', $names);
    }

    public function test_cookie_round_trip_authenticates_api_me(): void
    {
        $user = $this->createUser(attributes: ['email' => 'session-roundtrip@example.com']);
        $jar = $this->loginAndCaptureCookies($user->email);

        // Simula um processo novo: esvazia a sessão em memória para provar que
        // a autenticação vem do cookie, não de estado ambiente do teste.
        $this->simulateFreshProcess();

        $this->getJson('/api/me')->assertUnauthorized();

        $this->withSessionJar($jar)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_tampered_session_cookie_is_rejected(): void
    {
        $user = $this->createUser(attributes: ['email' => 'session-tamper@example.com']);
        $jar = $this->loginAndCaptureCookies($user->email);

        $this->simulateFreshProcess();

        $tampered = $jar;
        $tampered['cookies'][config('session.cookie')] = 'tampered';

        $this->withSessionJar($tampered)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_session_cookie(): void
    {
        $user = $this->createUser(attributes: ['email' => 'session-logout@example.com']);
        $jar = $this->loginAndCaptureCookies($user->email);

        $this->withSessionJar($jar)->postJson('/logout')->assertNoContent();

        $this->simulateFreshProcess();

        $this->withSessionJar($jar)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_request_with_invalid_csrf_token_is_rejected_with_419(): void
    {
        // O Laravel ignora CSRF com APP_ENV=testing; força um env real para
        // exercer a proteção de verdade, como o BFF enfrentará em produção.
        $user = $this->createUser(attributes: ['email' => 'session-csrf@example.com']);
        $jar = $this->loginAndCaptureCookies($user->email);

        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->withSessionJar($jar, 'invalid-token')->postJson('/logout')->assertStatus(419);

            // Controle: com o token válido, o mesmo cookie faz logout.
            $this->withSessionJar($jar)->postJson('/logout')->assertNoContent();
        } finally {
            $this->app['env'] = $previousEnv;
        }

        $this->assertGuest();
    }
}
