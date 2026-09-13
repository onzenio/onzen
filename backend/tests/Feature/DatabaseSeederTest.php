<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_plans_without_orphan_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertCount(3, Plan::all());
        $this->assertCount(1, Plan::where('is_default', true)->get());
    }
}
