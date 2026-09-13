<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_three_plans_with_exactly_one_default(): void
    {
        $this->seed(PlanSeeder::class);
        $this->assertCount(3, Plan::all());
        $this->assertCount(1, Plan::where('is_default', true)->get());
        $basic = Plan::where('is_default', true)->firstOrFail();
        $this->assertSame(3, $basic->max_users);
        $this->assertSame(10, $basic->max_clients);
        $this->assertSame(['clients'], $basic->modules);
        $this->assertSame(100, $basic->monthly_query_volume);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->assertCount(3, Plan::all());
        $this->assertCount(1, Plan::where('is_default', true)->get());
    }
}
