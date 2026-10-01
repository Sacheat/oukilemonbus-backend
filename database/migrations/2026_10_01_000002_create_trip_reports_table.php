<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte rendu d'un trajet par le chauffeur (cahier des charges §3 : « réalisation et confirmation des trajets »).
 * Un compte rendu par réservation, à part du cycle de statuts des réservations (qui reste inchangé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->unique();
            $table->unsignedBigInteger('driver_id')->nullable();          // chauffeur affecté au moment du compte rendu
            $table->string('outcome', 20);                                 // realise | non_realise
            $table->time('actual_departure_time')->nullable();             // heures réelles (réalisé)
            $table->time('actual_return_time')->nullable();
            $table->unsignedSmallInteger('actual_nb_children')->nullable(); // passagers réels (réalisé)
            $table->unsignedSmallInteger('actual_nb_adults')->nullable();
            $table->text('remarks')->nullable();                           // obligatoire si non réalisé (motif)
            $table->unsignedBigInteger('reported_by_user_id');             // dernier auteur (chauffeur ou agent)
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->nullOnDelete();
            $table->foreign('reported_by_user_id')->references('id')->on('users');
            $table->index('outcome', 'idx_trip_reports_outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_reports');
    }
};
