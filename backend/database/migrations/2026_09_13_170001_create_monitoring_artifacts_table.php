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
        Schema::create('monitoring_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            // monitoring_enrollments lands later; keep the link without a FK for now.
            $table->unsignedBigInteger('enrollment_id')->nullable()->index();
            $table->string('kind');
            $table->string('source')->nullable();
            $table->string('original_name')->nullable();
            $table->string('storage_path');
            $table->string('hash_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_artifacts');
    }
};
