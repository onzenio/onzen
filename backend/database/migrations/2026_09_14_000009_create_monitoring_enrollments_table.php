<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('definition_code', 60);
            $table->string('status', 20)->default('active');
            $table->string('pause_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'client_id', 'definition_code', 'status']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_enrollments');
    }
};
