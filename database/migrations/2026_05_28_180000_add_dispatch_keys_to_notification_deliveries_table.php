<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('event_key', 120)->nullable()->after('event_id');
            $table->string('dedupe_key', 191)->nullable()->after('event_key');
            $table->string('priority', 20)->default('normal')->after('channel');

            $table->index('event_key', 'idx_notification_deliveries_event_key');
            $table->unique('dedupe_key', 'uq_notification_deliveries_dedupe_key');
            $table->index('priority', 'idx_notification_deliveries_priority');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('idx_notification_deliveries_event_key');
            $table->dropUnique('uq_notification_deliveries_dedupe_key');
            $table->dropIndex('idx_notification_deliveries_priority');

            $table->dropColumn(['event_key', 'dedupe_key', 'priority']);
        });
    }
};

