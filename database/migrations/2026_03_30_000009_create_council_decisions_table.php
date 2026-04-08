<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('council_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->comment('Max 1 décision par réservation');
            $table->enum('decision_status', ['approuve', 'refuse', 'en_attente']);
            $table->date('decision_date')->nullable()->comment('Date de la séance du Collège');
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->string('decision_reference', 100)->nullable()->comment('Numéro officiel de délibération');
            $table->timestamp('validated_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique('reservation_id', 'uq_council_decisions_reservation_id');
            $table->index('decision_status', 'idx_council_decisions_status');
            $table->index('decision_date', 'idx_council_decisions_decision_date');
            $table->index('recorded_by_user_id', 'idx_council_decisions_recorded_by_user_id');
            $table->index('decision_reference', 'idx_council_decisions_decision_reference');

            $table->foreign('reservation_id')
                ->references('id')
                ->on('reservations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('recorded_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_decisions');
    }
};
