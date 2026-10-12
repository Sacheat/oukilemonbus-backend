<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RGPD, information des personnes (art. 13) : version de la politique de confidentialité dont la personne a pris
 * connaissance, et quand (PrivacyPolicy). Null : jamais vue (comptes antérieurs, comptes créés par un admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('privacy_policy_version', 30)->nullable()->after('inactivity_warned_at');
            $table->timestamp('privacy_policy_accepted_at')->nullable()->after('privacy_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_policy_version', 'privacy_policy_accepted_at']);
        });
    }
};
