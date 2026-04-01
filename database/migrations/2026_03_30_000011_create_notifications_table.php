<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('Destinataire');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->enum('type', [
                'reservation_submitted',
                'reservation_approved',
                'reservation_refused',
                'reservation_cancelled',
                'reservation_conflict',
                'pre_departure_reminder',
                'weekly_planning',
                'document_generated',
                'unavailability_created',
            ]);
            $table->enum('channel', ['email', 'app']);
            $table->enum('status', ['pending', 'sent', 'failed']);
            $table->string('title', 255);
            $table->text('message');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index('user_id', 'idx_notifications_user_id');
            $table->index('reservation_id', 'idx_notifications_reservation_id');
            $table->index('document_id', 'idx_notifications_document_id');
            $table->index('status', 'idx_notifications_status');
            $table->index('type', 'idx_notifications_type');
            $table->index('channel', 'idx_notifications_channel');
            $table->index('sent_at', 'idx_notifications_sent_at');
            $table->index('read_at', 'idx_notifications_read_at');
            $table->index(['user_id', 'status'], 'idx_notifications_user_status');
            $table->index(['user_id', 'read_at'], 'idx_notifications_user_read_at');
            $table->index(['user_id', 'created_at'], 'idx_notifications_user_created_at');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('reservation_id')
                ->references('id')
                ->on('reservations')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
