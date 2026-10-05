<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact de garde de la commune (facultatif, réglé par un admin dans Communes) : il reçoit par email les demandes
 * d'aide des chauffeurs, en plus des agents et admins. Ce n'est pas forcément un compte de l'application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('on_call_name', 150)->nullable()->after('max_pickup_distance_km');
            $table->string('on_call_email', 255)->nullable()->after('on_call_name');
            $table->string('on_call_phone', 30)->nullable()->after('on_call_email');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn(['on_call_name', 'on_call_email', 'on_call_phone']);
        });
    }
};
