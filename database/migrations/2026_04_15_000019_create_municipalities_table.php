<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('best_id', 500)->nullable();
            $table->string('nis_code', 20)->nullable();
            $table->string('country', 2)->default('BE');
            $table->string('postal_code', 20)->nullable();
            $table->string('source', 50)->nullable()->comment('manual | best_address | statbel | geonames');
            $table->boolean('is_active')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('name', 'idx_municipalities_name');
            $table->index('best_id', 'idx_municipalities_best_id');
            $table->index('nis_code', 'idx_municipalities_nis_code');
            $table->index('country', 'idx_municipalities_country');
            $table->index('is_active', 'idx_municipalities_is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipalities');
    }
};
