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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('cnpj', 14);
            $table->string('razao_social');
            $table->string('regime');
            $table->string('contador_responsavel');
            $table->boolean('monitoring_enabled')->default(false);
            $table->unique(['account_id', 'cnpj']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
