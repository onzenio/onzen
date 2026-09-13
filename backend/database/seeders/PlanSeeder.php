<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::updateOrCreate(['name' => 'Básico'], [
            'price_cents' => 0,
            'max_users' => 3,
            'max_clients' => 10,
            'modules' => ['clients'],
            'monthly_query_volume' => 100,
            'is_default' => true,
        ]);

        Plan::updateOrCreate(['name' => 'Intermediário'], [
            'price_cents' => 4990,
            'max_users' => 10,
            'max_clients' => 50,
            'modules' => ['clients'],
            'monthly_query_volume' => 1000,
            'is_default' => false,
        ]);

        Plan::updateOrCreate(['name' => 'Avançado'], [
            'price_cents' => 14990,
            'max_users' => 50,
            'max_clients' => 200,
            'modules' => ['clients'],
            'monthly_query_volume' => 10000,
            'is_default' => false,
        ]);
    }
}
