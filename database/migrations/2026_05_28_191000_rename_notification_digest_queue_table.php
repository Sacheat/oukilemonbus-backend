<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_digest_queue') && ! Schema::hasTable('notification_digest_queues')) {
            Schema::rename('notification_digest_queue', 'notification_digest_queues');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_digest_queues') && ! Schema::hasTable('notification_digest_queue')) {
            Schema::rename('notification_digest_queues', 'notification_digest_queue');
        }
    }
};

