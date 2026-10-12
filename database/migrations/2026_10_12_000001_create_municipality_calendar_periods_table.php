<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Périodes du calendrier d'une commune (jours fériés légaux : calculés, voir ClosedDaysService) :
 *   kind = fermeture          → aucune réservation possible (pont, fête locale…)
 *   kind = vacances_scolaires → les séries récurrentes sautent ces jours ; une réservation ponctuelle reste possible
 * Un seul jour : start_date = end_date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipality_calendar_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('municipality_id');
            $table->string('kind', 30)->comment('fermeture | vacances_scolaires');
            $table->string('label', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->index(['municipality_id', 'kind', 'start_date'], 'idx_calendar_periods_municipality_kind_start');

            $table->foreign('municipality_id')
                ->references('id')
                ->on('municipalities')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE municipality_calendar_periods ADD CONSTRAINT chk_calendar_periods_kind CHECK (kind IN ('fermeture', 'vacances_scolaires'))");
        DB::statement('ALTER TABLE municipality_calendar_periods ADD CONSTRAINT chk_calendar_periods_dates CHECK (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('municipality_calendar_periods');
    }
};
