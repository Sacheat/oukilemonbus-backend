<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->boolean('notify_reservation_updates_in_app')->default(true);
            $table->boolean('notify_reservation_updates_email')->default(true);

            $table->boolean('notify_driver_planning_in_app')->default(true);
            $table->boolean('notify_driver_planning_email')->default(true);

            $table->boolean('notify_admin_workflow_in_app')->default(true);
            $table->boolean('notify_admin_workflow_email')->default(true);

            $table->boolean('notify_last_minute_cancellation_in_app')->default(true);
            $table->boolean('notify_last_minute_cancellation_email')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};

