<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_runs', function (Blueprint $table) {
            $table->string('artifact_ref')->nullable();
            $table->text('artifact_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_runs', function (Blueprint $table) {
            $table->dropColumn(['artifact_ref', 'artifact_error']);
        });
    }
};
