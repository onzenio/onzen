<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('family', 60);
            $table->string('fingerprint', 64);
            $table->json('normalized');
            $table->unsignedInteger('version')->default(1);
            $table->string('completeness', 20)->default('complete');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'client_id', 'family']);
        });

        Schema::create('monitoring_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('monitoring_snapshot_id')->constrained('monitoring_snapshots')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->string('family', 60);
            $table->string('change_type', 40)->default('updated');
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('monitoring_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('family', 60);
            $table->foreignId('monitoring_change_id')->unique()->constrained('monitoring_changes')->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alerts');
        Schema::dropIfExists('monitoring_changes');
        Schema::dropIfExists('monitoring_snapshots');
    }
};
