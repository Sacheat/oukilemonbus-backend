<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Table pivot permettant de lier un document de feuille de route
 * aux réservations qu’il contient.
 *
 * La colonne position conserve l’ordre des réservations
 * dans le document généré.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reservation', function (Blueprint $table) {
            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('reservation_id')
                ->constrained('reservations')
                ->cascadeOnDelete();

            $table->unsignedInteger('position');

            $table->timestamps();

            $table->primary(['document_id', 'reservation_id']);

            $table->unique(['document_id', 'position']);

            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reservation');
    }
};
