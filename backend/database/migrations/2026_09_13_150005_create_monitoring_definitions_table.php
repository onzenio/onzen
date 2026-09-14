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
        Schema::create('monitoring_definitions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('category');
            $table->string('system');
            $table->text('description')->nullable();
            $table->string('version')->default('2026-08');
            $table->string('availability')->default('production');
            $table->string('strategy')->default('polling');
            $table->boolean('default_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_procuracao')->default(false);
            $table->json('operations')->nullable();
            $table->json('procuration_codes')->nullable();
            $table->json('person_types')->nullable();
            $table->json('regimes')->nullable();
            $table->json('services')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_definitions');
    }
};
