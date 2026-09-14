<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('family', 60);
            $table->string('name');
            $table->string('catalog_version', 20);
            $table->string('availability', 20)->default('available');
            $table->string('strategy', 40)->default('snapshot');
            $table->json('person_types')->nullable();
            $table->json('regimes')->nullable();
            $table->json('required_services')->nullable();
            $table->json('operations')->nullable();
            $table->boolean('automatic')->default(true);
            $table->text('unavailability_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_definitions');
    }
};
