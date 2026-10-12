<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RGPD, comptes inactifs (privacy:prune) : dernière activité (au plus une écriture par jour, EnsureUserIsActive)
 * et date de l'avertissement envoyé avant l'anonymisation automatique. Sans activité connue, created_at fait foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('anonymized_at');
            $table->timestamp('inactivity_warned_at')->nullable()->after('last_seen_at');
            $table->index('last_seen_at', 'idx_users_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_last_seen_at');
            $table->dropColumn(['last_seen_at', 'inactivity_warned_at']);
        });
    }
};
