<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unavailabilities_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('unavailability_id');
            $table->string('old_reason_type', 50)->nullable();
            $table->string('new_reason_type', 50)->nullable();
            $table->dateTime('old_start_datetime')->nullable();
            $table->dateTime('new_start_datetime')->nullable();
            $table->dateTime('old_end_datetime')->nullable();
            $table->dateTime('new_end_datetime')->nullable();
            $table->string('old_reason', 500)->nullable();
            $table->string('new_reason', 500)->nullable();
            $table->unsignedBigInteger('changed_by_user_id');
            $table->string('change_reason', 500)->nullable();
            $table->timestamp('created_at');

            $table->index('unavailability_id', 'idx_unavailabilities_history_unavailability_id');
            $table->index('changed_by_user_id', 'idx_unavailabilities_history_changed_by_user_id');
            $table->index('created_at', 'idx_unavailabilities_history_created_at');
            $table->index(['unavailability_id', 'created_at'], 'idx_unavailabilities_history_unavailability_created_at');

            $table->foreign('unavailability_id')
                ->references('id')
                ->on('unavailabilities')
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
        Schema::dropIfExists('unavailabilities_history');
    }
};
