<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipality_localities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('municipality_id');
            $table->string('name', 100);
            $table->string('normalized_name', 120);
            $table->string('best_id', 500)->nullable();
            $table->string('source', 50)->nullable()->comment('manual | best_address | statbel | geonames');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('municipality_id', 'idx_municipality_localities_municipality_id');
            $table->index('normalized_name', 'idx_municipality_localities_normalized_name');
            $table->index('best_id', 'idx_municipality_localities_best_id');
            $table->index('is_active', 'idx_municipality_localities_is_active');
            $table->unique(['municipality_id', 'normalized_name'], 'uq_municipality_localities_municipality_normalized_name');

            $table->foreign('municipality_id')
                ->references('id')
                ->on('municipalities')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipality_localities');
    }
};
