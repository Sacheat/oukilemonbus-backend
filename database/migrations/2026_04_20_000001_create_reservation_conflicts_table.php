<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_conflicts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->string('type', 80);
            $table->string('message', 500);
            $table->unsignedBigInteger('related_reservation_id')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at');

            $table->index('reservation_id', 'idx_reservation_conflicts_reservation_id');
            $table->index('type', 'idx_reservation_conflicts_type');
            $table->index('is_resolved', 'idx_reservation_conflicts_is_resolved');
            $table->index('related_reservation_id', 'idx_reservation_conflicts_related_reservation_id');
            $table->index('resolved_by_user_id', 'idx_reservation_conflicts_resolved_by_user_id');

            $table->foreign('reservation_id')
                ->references('id')
                ->on('reservations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('related_reservation_id')
                ->references('id')
                ->on('reservations')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('resolved_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_conflicts');
    }
};
