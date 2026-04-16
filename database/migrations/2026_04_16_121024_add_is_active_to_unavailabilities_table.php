<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unavailabilities', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('end_datetime');
            $table->index('is_active', 'idx_unavailabilities_is_active');
        });
    }

    public function down(): void
    {
        Schema::table('unavailabilities', function (Blueprint $table) {
            $table->dropIndex('idx_unavailabilities_is_active');
            $table->dropColumn('is_active');
        });
    }
};
