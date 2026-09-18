<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Models\Account;
use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_database_rejects_a_second_profile_a_account(): void
    {
        $this->createAccount(['profile' => AccountProfile::A]);

        $this->expectException(QueryException::class);

        $this->createAccount(['profile' => AccountProfile::A]);
    }

    public function test_database_allows_multiple_profile_b_accounts(): void
    {
        $this->createAccount(['profile' => AccountProfile::A]);
        $this->createAccount(['profile' => AccountProfile::B]);
        $this->createAccount(['profile' => AccountProfile::B]);

        $this->assertSame(1, Account::query()->withoutGlobalScopes()->where('profile', AccountProfile::A)->count());
        $this->assertSame(2, Account::query()->withoutGlobalScopes()->where('profile', AccountProfile::B)->count());
    }

    public function test_partial_unique_index_for_single_profile_a_exists(): void
    {
        $indexes = collect(DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'accounts'"))
            ->map(fn ($row) => (string) $row->sql)
            ->implode("\n");

        $this->assertStringContainsString('accounts_single_profile_a', $indexes);
        $this->assertStringContainsString('WHERE', $indexes);
    }
}
