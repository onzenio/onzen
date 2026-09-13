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
        Schema::create('query_quota_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('run_id')->unique()->constrained('monitoring_runs')->restrictOnDelete();
            $table->string('trigger');
            $table->string('period', 7);
            $table->timestamp('created_at')->nullable();
            $table->index(['account_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_quota_consumptions');
    }
};
