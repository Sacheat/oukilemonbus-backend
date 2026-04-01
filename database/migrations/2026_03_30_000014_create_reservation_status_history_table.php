<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->enum('old_status', ['en_attente', 'approuve', 'refuse', 'annule', 'en_conflit']);
            $table->enum('new_status', ['en_attente', 'approuve', 'refuse', 'annule', 'en_conflit']);
            $table->unsignedBigInteger('changed_by_user_id');
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index('reservation_id', 'idx_reservation_status_history_reservation_id');
            $table->index('changed_by_user_id', 'idx_reservation_status_history_changed_by_user_id');
            $table->index('created_at', 'idx_reservation_status_history_created_at');
            $table->index(['reservation_id', 'created_at'], 'idx_reservation_status_history_reservation_created_at');
            $table->index('new_status', 'idx_reservation_status_history_new_status');

            $table->foreign('reservation_id')
                ->references('id')
                ->on('reservations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('changed_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_history');
    }
};
