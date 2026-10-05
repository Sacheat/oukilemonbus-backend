<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Événements d'un trajet (« Ma journée » du chauffeur) : ce qui s'est passé, quand, et qui l'a signalé.
 *
 * Une ligne par étape et par réservation (départ aller, arrivée à destination, départ retour, arrivée au lieu de
 * départ). `source` dit qui a produit le fait : le chauffeur (bouton), un agent (correction), plus tard un suivi GPS
 * automatique. L'avancement du trajet est calculé à partir de ces lignes, sans regarder leur source : un futur
 * producteur (application de suivi, géorepérage) écrira les mêmes lignes sans changer le reste.
 *
 * La position est relevée au moment du fait quand le téléphone la donne ; `position_state` garde la trace d'un refus
 * ou d'une absence de position (règlement interne : le partage est attendu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('driver_id')->nullable();             // chauffeur affecté au moment du fait
            $table->string('type', 30);                                       // TripEventType
            $table->timestamp('occurred_at');                                 // instant du fait (UTC)
            $table->string('source', 20);                                     // TripEventSource : chauffeur | agent | gps | systeme
            $table->string('position_state', 20);                             // PositionState : envoyee | refusee | indisponible
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();                // précision donnée par le téléphone (mètres)
            $table->timestamp('position_captured_at')->nullable();            // instant de la mesure, selon le téléphone
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();    // null : fait produit par le système
            $table->timestamps();

            $table->unique(['reservation_id', 'type'], 'uniq_trip_events_reservation_type');
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->nullOnDelete();
            $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('occurred_at', 'idx_trip_events_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_events');
    }
};
