<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('powers_of_attorney', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('service_code', 60);
            $table->string('status', 20)->default('missing');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'client_id', 'service_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('powers_of_attorney');
    }
};
