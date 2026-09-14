<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_request_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('account_certificate_id')->nullable()->constrained('account_certificates');
            $table->string('document', 18);
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->string('token_ref')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_request_authors');
    }
};
