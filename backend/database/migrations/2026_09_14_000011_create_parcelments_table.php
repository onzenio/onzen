<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcelment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('modality_code', 20);
            $table->string('order_number', 60);
            $table->string('status', 30)->default('ativo');
            $table->string('total_value', 30)->nullable();
            $table->string('guia_ref')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'client_id', 'modality_code', 'order_number']);
        });

        Schema::create('parcelment_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcelment_order_id')->constrained('parcelment_orders')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('due_date', 20)->nullable();
            $table->string('value', 30)->nullable();
            $table->string('status', 30)->default('aberta');
            $table->timestamps();
        });

        Schema::create('parcelment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcelment_order_id')->constrained('parcelment_orders')->cascadeOnDelete();
            $table->string('paid_at', 20)->nullable();
            $table->string('value', 30)->nullable();
            $table->string('receipt', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcelment_payments');
        Schema::dropIfExists('parcelment_installments');
        Schema::dropIfExists('parcelment_orders');
    }
};
