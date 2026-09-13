<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('accounts', [
            'id', 'name', 'profile', 'plan_id',
        ]));
    }

    public function test_account_persists_with_profile_a_and_plan_relation(): void
    {
        $plan = Plan::factory()->create(['name' => 'Básico']);

        $account = $this->createAccount([
            'name' => 'OneFisc',
            'profile' => AccountProfile::A,
            'plan_id' => $plan->id,
        ]);

        $this->assertDatabaseHas('accounts', ['name' => 'OneFisc', 'profile' => 'A']);
        $this->assertSame(AccountProfile::A, $account->refresh()->profile);
        $this->assertTrue($account->plan->is($plan));
    }

    public function test_account_persists_with_profile_b_without_plan(): void
    {
        $account = $this->createAccount(['profile' => AccountProfile::B, 'plan_id' => null]);

        $this->assertSame(AccountProfile::B, $account->refresh()->profile);
        $this->assertNull($account->plan);
    }
}
