<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();
        $constraint = 'chk_'.$prefix.'unavailabilities_logic';

        Schema::create('unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->string('reason_type', 50);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('driver_id')->nullable()->comment('Renseigné si reason_type = driver');
            $table->unsignedBigInteger('vehicle_id')->nullable()->comment('Renseigné si reason_type = vehicle');
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->index('reason_type', 'idx_unavailabilities_reason_type');
            $table->index('driver_id', 'idx_unavailabilities_driver_id');
            $table->index('vehicle_id', 'idx_unavailabilities_vehicle_id');
            $table->index('created_by_user_id', 'idx_unavailabilities_created_by_user_id');
            $table->index(['start_datetime', 'end_datetime'], 'idx_unavailabilities_start_end');
            $table->index(['driver_id', 'start_datetime'], 'idx_unavailabilities_driver_start');
            $table->index(['vehicle_id', 'start_datetime'], 'idx_unavailabilities_vehicle_start');

            $table->foreign('driver_id')
                ->references('id')
                ->on('drivers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        DB::statement("
            ALTER TABLE {$prefix}unavailabilities
            ADD CONSTRAINT {$constraint}
            CHECK (
                end_datetime > start_datetime
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('unavailabilities');
    }
};
