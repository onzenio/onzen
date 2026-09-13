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
        Schema::create('monitoring_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('definition_id');
            $table->string('status')->default('active')->index();
            $table->string('pause_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->json('configuration')->nullable();
            $table->timestamp('last_change_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'client_id', 'definition_id'], 'monitoring_enrollments_scope_unique');
            $table->foreign('definition_id')->references('id')->on('monitoring_definitions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_enrollments');
    }
};
