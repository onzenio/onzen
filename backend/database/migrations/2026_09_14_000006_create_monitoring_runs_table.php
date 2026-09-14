<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->nullable()->constrained('clients');
            $table->string('definition_code', 60);
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 120);
            $table->unsignedInteger('fencing_token')->default(1);
            $table->string('origin', 20)->default('manual');
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->string('protocol', 120)->nullable();
            $table->json('result_summary')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'idempotency_key']);
            $table->index(['account_id', 'status']);
        });

        Schema::create('monitoring_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitoring_run_id')->constrained('monitoring_runs')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->integer('status_code')->nullable();
            $table->string('outcome', 20);
            $table->json('response_summary')->nullable();
            $table->unsignedInteger('backoff_seconds')->nullable();
            $table->timestamps();
            $table->unique(['monitoring_run_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_attempts');
        Schema::dropIfExists('monitoring_runs');
    }
};
