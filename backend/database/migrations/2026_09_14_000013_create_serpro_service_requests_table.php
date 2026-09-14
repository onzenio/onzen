<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serpro_contracts', function (Blueprint $table) {
            $table->string('contractor_document', 18)->nullable();
        });

        Schema::create('serpro_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('kind', 40);
            $table->string('idempotency_key', 120);
            $table->string('status', 20)->default('pending');
            $table->boolean('confirmed')->default(false);
            $table->string('protocol', 120)->nullable();
            $table->string('artifact_ref')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_service_requests');
        Schema::table('serpro_contracts', function (Blueprint $table) {
            $table->dropColumn('contractor_document');
        });
    }
};
