<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            // Centre de la commune (renseigné par la synchronisation GeoNames ou à la main) :
            // point de référence si aucun dépôt n'est défini.
            $table->decimal('latitude', 10, 8)->nullable()->after('postal_code');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->foreignId('depot_location_id')->nullable()->after('longitude')
                ->constrained('locations')->nullOnDelete();
            // NULL = valeur par défaut de config/reservations.php.
            $table->unsignedSmallInteger('max_trip_distance_km')->nullable()->after('depot_location_id')
                ->comment('Distance max départ → destination');
            $table->unsignedSmallInteger('max_pickup_distance_km')->nullable()->after('max_trip_distance_km')
                ->comment('Distance max dépôt (ou centre) → lieu de départ, à vol d’oiseau');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depot_location_id');
            $table->dropColumn(['latitude', 'longitude', 'max_trip_distance_km', 'max_pickup_distance_km']);
        });
    }
};
