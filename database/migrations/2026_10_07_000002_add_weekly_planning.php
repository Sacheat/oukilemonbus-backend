<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning hebdomadaire envoyé automatiquement (§5.9) :
 *   - réglages de la commune (admins) : envoi activé, jour et heure, responsable du planning (null = défaut / tous) ;
 *   - préférence de chaque agent et admin : null = automatique (le responsable s'il y en a un, sinon tous),
 *     true = toujours recevoir, false = ne jamais recevoir ; canaux cloche et email ;
 *   - weekly_planning_dispatches : une ligne par envoi (version 1, puis versions corrigées) de chaque semaine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->boolean('weekly_planning_enabled')->nullable()->after('driver_departure_window_min');
            $table->unsignedTinyInteger('weekly_planning_send_day')->nullable()->after('weekly_planning_enabled');
            $table->string('weekly_planning_send_time', 5)->nullable()->after('weekly_planning_send_day');
            $table->foreignId('weekly_planning_owner_user_id')->nullable()->after('weekly_planning_send_time')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->boolean('weekly_planning_opt_in')->nullable()->after('notify_last_minute_cancellation_email');
            $table->boolean('notify_weekly_planning_in_app')->default(true)->after('weekly_planning_opt_in');
            $table->boolean('notify_weekly_planning_email')->default(true)->after('notify_weekly_planning_in_app');
        });

        Schema::create('weekly_planning_dispatches', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');                        // lundi de la semaine du planning
            $table->unsignedSmallInteger('version');           // 1 = envoi initial, 2… = versions corrigées
            $table->string('signature', 64);                   // empreinte des trajets du fichier (détection des changements)
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->unsignedSmallInteger('trip_count');
            $table->unsignedSmallInteger('pending_count');     // demandes en attente ou en conflit, hors fichier
            $table->unsignedSmallInteger('recipient_count');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['week_start', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_planning_dispatches');

        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['weekly_planning_opt_in', 'notify_weekly_planning_in_app', 'notify_weekly_planning_email']);
        });

        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weekly_planning_owner_user_id');
            $table->dropColumn(['weekly_planning_enabled', 'weekly_planning_send_day', 'weekly_planning_send_time']);
        });
    }
};
