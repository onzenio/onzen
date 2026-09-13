<?php

namespace Tests\Unit;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_account_profile_values(): void
    {
        $this->assertSame('A', AccountProfile::A->value);
        $this->assertSame('B', AccountProfile::B->value);
    }

    public function test_account_profile_rejects_invalid_value(): void
    {
        $this->assertNull(AccountProfile::tryFrom('C'));
        $this->expectException(\ValueError::class);

        AccountProfile::from('C');
    }

    public function test_user_role_values(): void
    {
        $this->assertSame('super_admin', UserRole::SuperAdmin->value);
        $this->assertSame('admin', UserRole::Admin->value);
        $this->assertSame('operator', UserRole::Operator->value);
        $this->assertSame('user', UserRole::User->value);
    }

    public function test_user_role_rejects_invalid_value(): void
    {
        $this->assertNull(UserRole::tryFrom('operador'));
        $this->expectException(\ValueError::class);

        UserRole::from('operador');
    }
}
