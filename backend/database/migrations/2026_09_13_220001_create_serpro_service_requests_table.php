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
        Schema::create('serpro_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('monitoring_enrollments')->nullOnDelete();
            $table->foreignId('installment_id')->nullable()->constrained('parcelment_installments')->nullOnDelete();
            $table->string('operation_code')->index();
            $table->string('modality')->nullable();
            $table->string('idempotency_key', 128);
            $table->string('status')->default('pending')->index();
            $table->string('protocol')->nullable();
            $table->string('document_ref')->nullable();
            $table->json('parameters')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['account_id', 'idempotency_key'], 'serpro_service_requests_scope_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_service_requests');
    }
};
