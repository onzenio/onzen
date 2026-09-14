<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('name', 120);
            $table->text('ciphertext');
            $table->timestamps();
            $table->unique(['account_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_secrets');
    }
};
