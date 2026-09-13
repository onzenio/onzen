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
        Schema::create('parcelment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('monitoring_enrollments')->nullOnDelete();
            $table->string('modality')->index();
            $table->string('external_id')->index();
            $table->string('status')->nullable();
            $table->unsignedInteger('installments_count')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->date('competence')->nullable();
            $table->string('provenance')->default('serpro');
            $table->string('operation_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'client_id', 'modality', 'external_id'], 'parcelment_orders_scope_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcelment_orders');
    }
};
