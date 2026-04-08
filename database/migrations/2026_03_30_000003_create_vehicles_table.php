<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('licence_plate', 20)->comment('1-VJX-649');
            $table->integer('capacity')->comment('41 places chauffeur compris');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('licence_plate', 'uq_vehicles_licence_plate');
            $table->index('is_active', 'idx_vehicles_is_active');
            $table->index('capacity', 'idx_vehicles_capacity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
