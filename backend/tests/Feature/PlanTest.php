<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('plans', [
            'id', 'name', 'max_users', 'max_clients', 'modules',
            'monthly_query_volume', 'price_cents', 'is_default',
        ]));
    }

    public function test_plan_persists_with_casts(): void
    {
        $plan = Plan::factory()->create([
            'name' => 'Básico',
            'max_users' => 3,
            'max_clients' => 10,
            'modules' => ['clients'],
            'monthly_query_volume' => 100,
            'price_cents' => 0,
            'is_default' => true,
        ]);

        $this->assertDatabaseHas('plans', ['name' => 'Básico', 'is_default' => true]);
        $this->assertSame(['clients'], $plan->refresh()->modules);
        $this->assertTrue($plan->is_default);
    }
}
