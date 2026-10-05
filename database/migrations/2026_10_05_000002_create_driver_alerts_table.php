<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'aide du chauffeur (bouton « Aide » : panne, accident, problème à bord).
 *
 * Cycle : ouverte → prise_en_charge (un agent s'en occupe) → cloturee (avec une note). La position est celle du
 * téléphone à l'envoi (ou au dernier « Renvoyer ma position ») ; sans position, `fallback_*` garde le dernier lieu
 * connu, déduit de l'avancement du trajet (lieu de départ ou destination).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('reservation_id')->nullable();   // trajet en cours (facultatif : aide entre deux trajets)
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('status', 20);                                // DriverAlertStatus
            $table->text('message')->nullable();                         // texte libre du chauffeur
            $table->string('trip_progress', 30)->nullable();             // TripProgressState au moment de l'alerte

            $table->string('position_state', 20);                        // PositionState
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->timestamp('position_captured_at')->nullable();
            $table->unsignedSmallInteger('position_updates')->default(0); // nombre de « Renvoyer ma position »

            $table->string('fallback_label', 255)->nullable();           // dernier lieu connu, sans position
            $table->decimal('fallback_latitude', 10, 7)->nullable();
            $table->decimal('fallback_longitude', 10, 7)->nullable();

            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('handled_by_user_id')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();

            $table->foreign('driver_id')->references('id')->on('drivers')->cascadeOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->nullOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->foreign('handled_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'created_at'], 'idx_driver_alerts_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_alerts');
    }
};
