<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table): void {
            $table->string('digest_frequency', 20)->default('none')->after('notify_last_minute_cancellation_email');
            $table->unsignedTinyInteger('quiet_hours_start')->nullable()->after('digest_frequency');
            $table->unsignedTinyInteger('quiet_hours_end')->nullable()->after('quiet_hours_start');
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['digest_frequency', 'quiet_hours_start', 'quiet_hours_end']);
        });
    }
};

