<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrences', function (Blueprint $table) {
            $table->id();
            $table->string('frequency', 50)->comment('hebdomadaire | bihebdomadaire | mensuel');
            $table->integer('interval_value')->comment('Nombre de jours entre occurrences');
            $table->string('days_of_week', 50)->nullable()->comment("Ex: '1,3' pour lundi et mercredi");
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('occurrence_count')->nullable()->comment('Nb max occurrences (optionnel)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('frequency', 'idx_recurrences_frequency');
            $table->index(['start_date', 'end_date'], 'idx_recurrences_start_end');
            $table->index('is_active', 'idx_recurrences_is_active');
        });

        DB::statement("
            ALTER TABLE recurrences
            ADD CONSTRAINT chk_recurrences_values
            CHECK (
                interval_value > 0
                AND end_date >= start_date
                AND (occurrence_count IS NULL OR occurrence_count > 0)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrences');
    }
};
