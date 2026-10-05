<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Ma journée », partie 2 :
 *   - réglages de la commune (page Communes, admins) : application de navigation et délai du premier départ ;
 *     null = valeur par défaut de config/driver_journey.php ;
 *   - trip_routes : tracé réel d'un trajet (OpenRouteService), calculé à la première ouverture d'une mission et gardé ;
 *     recalculé seulement si le lieu de départ ou la destination change (signature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('navigation_engine', 30)->nullable()->after('on_call_phone');
            $table->unsignedSmallInteger('driver_departure_window_min')->nullable()->after('navigation_engine');
        });

        Schema::create('trip_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->unique();
            $table->string('signature', 120);                 // lieux et coordonnées utilisés pour le calcul
            $table->json('geometry');                          // [[lat, lng], …] du lieu de départ à la destination
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('duration_s');
            $table->json('steps');                             // [{ instruction, name, distance_m, duration_s }]
            $table->string('provider', 30)->default('openrouteservice');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_routes');

        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn(['navigation_engine', 'driver_departure_window_min']);
        });
    }
};
