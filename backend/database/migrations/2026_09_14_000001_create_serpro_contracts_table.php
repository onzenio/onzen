<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('homologacao');
            $table->string('consumer_key_ref')->nullable();
            $table->string('consumer_secret_ref')->nullable();
            $table->boolean('transport_approved')->default(false);
            $table->timestamps();
            $table->unique('environment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_contracts');
    }
};
