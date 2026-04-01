<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->enum('assignment_type', ['driver', 'vehicle']);
            $table->unsignedBigInteger('old_driver_id')->nullable();
            $table->unsignedBigInteger('new_driver_id')->nullable();
            $table->unsignedBigInteger('old_vehicle_id')->nullable();
            $table->unsignedBigInteger('new_vehicle_id')->nullable();
            $table->unsignedBigInteger('changed_by_user_id');
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index('reservation_id', 'idx_reservation_assignment_history_reservation_id');
            $table->index('assignment_type', 'idx_reservation_assignment_history_assignment_type');
            $table->index('changed_by_user_id', 'idx_reservation_assignment_history_changed_by_user_id');
            $table->index('created_at', 'idx_reservation_assignment_history_created_at');
            $table->index(['reservation_id', 'created_at'], 'idx_reservation_assignment_history_reservation_created_at');
            $table->index('new_driver_id', 'idx_reservation_assignment_history_new_driver_id');
            $table->index('new_vehicle_id', 'idx_reservation_assignment_history_new_vehicle_id');

            $table->foreign('reservation_id')
                ->references('id')
                ->on('reservations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('old_driver_id')
                ->references('id')
                ->on('drivers')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('new_driver_id')
                ->references('id')
                ->on('drivers')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('old_vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('new_vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('changed_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_assignment_history');
    }
};
