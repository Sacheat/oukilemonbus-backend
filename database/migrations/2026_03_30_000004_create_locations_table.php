<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('formatted_address', 500);
            $table->string('street', 255)->nullable();
            $table->string('street_number', 20)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100);
            $table->string('country', 100)->default('Belgique');
            $table->decimal('latitude', 11, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('place_id', 255)->nullable()->comment('ID Google Maps / ORS — cache distances : pas de nouvel appel API si déjà géocodé');
            $table->string('provider', 50)->nullable()->comment('google | openrouteservice');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index('city', 'idx_locations_city');
            $table->index(['postal_code', 'city'], 'idx_locations_postal_city');
            $table->index(['latitude', 'longitude'], 'idx_locations_lat_lng');
            $table->index('place_id', 'idx_locations_place_id');
            $table->index(['provider', 'place_id'], 'idx_locations_provider_place_id');
            $table->index('is_verified', 'idx_locations_is_verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
