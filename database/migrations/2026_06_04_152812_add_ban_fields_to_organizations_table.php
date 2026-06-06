<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_banned')->default(false)->after('is_priority');
            $table->string('banned_reason', 500)->nullable()->after('is_banned');
            $table->index('is_banned', 'idx_organizations_is_banned');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex('idx_organizations_is_banned');
            $table->dropColumn(['is_banned', 'banned_reason']);
        });
    }
};
