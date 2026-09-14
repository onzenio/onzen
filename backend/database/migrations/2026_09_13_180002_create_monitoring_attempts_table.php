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
        Schema::create('monitoring_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('monitoring_runs')->cascadeOnDelete();
            $table->unsignedInteger('attempt')->default(1);
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('response_code')->nullable();
            $table->string('classification')->nullable();
            $table->unsignedInteger('retry_after')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'attempt']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_attempts');
    }
};
