<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monitoring_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('enrollment_id')->constrained('monitoring_enrollments')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->unique()->constrained('monitoring_runs')->nullOnDelete();
            $table->string('operation_code')->index();
            $table->string('family');
            $table->boolean('normalized')->default(true);
            $table->char('fingerprint', 64);
            $table->json('data');
            $table->string('freshness')->default('fresh')->index();
            $table->string('completeness')->default('complete')->index();
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->index(['enrollment_id', 'operation_code', 'verified_at'], 'monitoring_snapshots_current_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_snapshots');
    }
};
