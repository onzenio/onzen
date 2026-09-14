<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Garante no máximo uma Account com profile=A (fail-closed contra
     * corrida no Onboarding). Múltiplas Accounts B seguem permitidas.
     */
    public function up(): void
    {
        // Preflight factual: base previamente inconsistente aborta sem
        // apagar ou escolher dados automaticamente.
        $duplicates = DB::table('accounts')->where('profile', 'A')->count();

        if ($duplicates > 1) {
            throw new RuntimeException(
                "Migração abortada: existem {$duplicates} Accounts com profile=A. ".
                'Resolva manualmente antes de aplicar a restrição de unicidade.'
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX accounts_single_profile_a ON accounts (profile) WHERE profile = \'A\'');
        } elseif ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX accounts_single_profile_a ON accounts (profile) WHERE profile = \'A\'');
        } else {
            Schema::table('accounts', function (Blueprint $table) {
                $table->unique('profile', 'accounts_single_profile_a');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS accounts_single_profile_a');
        } else {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropUnique('accounts_single_profile_a');
            });
        }
    }
};
