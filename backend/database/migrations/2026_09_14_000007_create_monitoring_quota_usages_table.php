<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_quota_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('monitoring_run_id')->unique()->constrained('monitoring_runs');
            $table->string('origin', 20)->default('manual');
            $table->string('period', 7);
            $table->timestamps();
            $table->index(['account_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_quota_usages');
    }
};
