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
        Schema::create('parcelment_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('parcelment_orders')->cascadeOnDelete();
            $table->string('external_id');
            $table->unsignedInteger('number');
            $table->string('status')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('guide_ref')->nullable();
            $table->string('provenance')->default('serpro');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'order_id', 'number'], 'parcelment_installments_scope_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcelment_installments');
    }
};
