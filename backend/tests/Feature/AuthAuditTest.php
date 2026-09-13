<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_records_exactly_one_audit_row(): void
    {
        $this->createUser(attributes: ['email' => 'audit-once@example.com']);

        $this->postJson('/login', ['email' => 'audit-once@example.com', 'password' => 'password'])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login')->count());
    }

    public function test_logout_records_exactly_one_audit_row(): void
    {
        $this->createUser(attributes: ['email' => 'audit-once-logout@example.com']);

        $login = $this->postJson('/login', ['email' => 'audit-once-logout@example.com', 'password' => 'password']);
        $login->assertOk();

        $cookies = [];
        $xsrf = null;
        foreach ($login->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $xsrf = $cookie->getValue();
            }
        }

        $this->withCredentials()->withUnencryptedCookies($cookies)->withHeaders([
            'X-XSRF-TOKEN' => $xsrf,
            'Origin' => 'http://localhost:3000',
        ])->postJson('/logout')->assertNoContent();

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.logout')->count());
    }
}
