<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('pfx_ref');
            $table->string('password_ref');
            $table->string('holder_name');
            $table->string('thumbprint', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_certificates');
    }
};
