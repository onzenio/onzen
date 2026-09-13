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
        Schema::create('monitoring_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('enrollment_id')->constrained('monitoring_enrollments')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('snapshot_id')->unique()->constrained('monitoring_snapshots')->cascadeOnDelete();
            $table->foreignId('previous_snapshot_id')->nullable()->constrained('monitoring_snapshots')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('monitoring_runs')->nullOnDelete();
            $table->string('operation_code')->index();
            $table->string('kind');
            $table->json('data');
            $table->timestamp('created_at')->nullable();
            $table->index(['enrollment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_changes');
    }
};
