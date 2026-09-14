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
        Schema::create('serpro_request_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('document', 14);
            $table->unsignedTinyInteger('document_type'); // 1 = PF | 2 = PJ
            $table->string('name');
            $table->string('status', 20)->default('active'); // active | ineligible
            $table->string('certificate_thumbprint')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_request_authors');
    }
};
