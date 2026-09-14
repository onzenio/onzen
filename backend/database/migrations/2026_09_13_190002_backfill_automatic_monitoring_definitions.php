<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the automatic cycle marker (Task 18 / design decision 6).
 *
 * Task 2 seeded every definition with `strategy = polling`; the three legacy
 * automatic-cycle definitions must carry `strategy = automatic` so the
 * monthly command selects them from metadata instead of a hardcoded list.
 * Idempotent: only rows still on the default `polling` (or NULL) change, and
 * re-running is a no-op. Fresh installs get the same value from
 * MonitoringDefinitionSeeder.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const AUTOMATIC_DEFINITIONS = ['pgdas-declaracoes', 'dctfweb', 'situacao-fiscal'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('monitoring_definitions')
            ->whereIn('id', self::AUTOMATIC_DEFINITIONS)
            ->where(function ($query): void {
                $query->whereNull('strategy')->orWhere('strategy', 'polling');
            })
            ->update(['strategy' => 'automatic', 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('monitoring_definitions')
            ->whereIn('id', self::AUTOMATIC_DEFINITIONS)
            ->where('strategy', 'automatic')
            ->update(['strategy' => 'polling', 'updated_at' => now()]);
    }
};
