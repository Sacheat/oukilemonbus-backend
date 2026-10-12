<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RGPD, droit à l'effacement : date d'anonymisation du compte (UserAnonymizationService). Le compte reste en base
 * pour l'historique (réservations, comptes rendus) mais ne porte plus aucune donnée identifiante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
