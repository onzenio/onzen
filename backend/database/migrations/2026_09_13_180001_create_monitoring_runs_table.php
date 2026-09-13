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
        Schema::create('monitoring_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('enrollment_id')->constrained('monitoring_enrollments')->cascadeOnDelete();
            $table->string('trigger')->default('manual');
            $table->string('definition_key');
            $table->string('operation_code')->nullable();
            $table->string('idempotency_key');
            $table->unsignedInteger('fencing_token');
            $table->string('status')->default('pending')->index();
            $table->string('environment')->default('homologacao');
            $table->boolean('dry_run')->default(true);
            $table->string('protocol')->nullable()->index();
            $table->timestamp('eta')->nullable();
            $table->json('parameters')->nullable();
            $table->string('external_code')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_runs');
    }
};
