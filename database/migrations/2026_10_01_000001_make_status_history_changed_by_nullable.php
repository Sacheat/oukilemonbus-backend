<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un changement de statut peut être fait par le système, sans utilisateur (clôture automatique des réservations
 * dont la date est dépassée) : changed_by_user_id devient facultatif. Null = action du système.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_status_history', function (Blueprint $table) {
            $table->unsignedBigInteger('changed_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_status_history', function (Blueprint $table) {
            $table->unsignedBigInteger('changed_by_user_id')->nullable(false)->change();
        });
    }
};
