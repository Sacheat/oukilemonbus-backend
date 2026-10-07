<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relance d'une demande d'aide restée ouverte (driver-alerts:remind) : heure de la relance, une seule par alerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_alerts', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('handled_at');
            $table->index(['status', 'reminded_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('driver_alerts', function (Blueprint $table) {
            $table->dropIndex(['status', 'reminded_at', 'created_at']);
            $table->dropColumn('reminded_at');
        });
    }
};
