<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_digest_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('category', 100);
            $table->string('priority', 20)->default('normal');
            $table->string('title', 255);
            $table->text('message');
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sent_at'], 'idx_notification_digest_queues_user_sent');
            $table->index(['available_at', 'sent_at'], 'idx_notification_digest_queues_available_sent');
            $table->index(['category', 'priority'], 'idx_notification_digest_queues_category_priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_queues');
    }
};
