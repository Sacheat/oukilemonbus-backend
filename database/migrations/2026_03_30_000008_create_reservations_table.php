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
        $constraint = 'chk_'.$prefix.'reservations_business_rules';

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('pickup_location_id');
            $table->unsignedBigInteger('destination_location_id');
            $table->unsignedBigInteger('driver_id')->nullable()->comment('Assigné après validation');
            $table->unsignedBigInteger('vehicle_id')->nullable()->comment('Assigné après validation');
            $table->unsignedBigInteger('recurrence_id')->nullable();
            $table->date('trip_date');
            $table->time('departure_time');
            $table->time('return_time')->comment('Heure de retour souhaitée');
            $table->integer('nb_children')->default(0);
            $table->integer('nb_adults')->comment('Min 1 adulte accompagnant');
            $table->string('trip_purpose', 500)->comment('Objet du déplacement');
            $table->string('activity_type', 50)->default('standard')->comment('Priorite interne entre ecoles communales');
            $table->decimal('distance_km', 6, 2)->nullable()->comment('Calculé via API');
            $table->integer('estimated_duration_min')->nullable()->comment('Durée estimée API + marge configurable');
            $table->string('status', 50);
            $table->string('approval_type', 50);
            $table->unsignedBigInteger('validated_by_user_id')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['trip_date', 'status'], 'idx_reservations_date_status');
            $table->index(['organization_id', 'trip_date'], 'idx_reservations_org_date');
            $table->index('requested_by_user_id', 'idx_reservations_requested_by_user_id');
            $table->index('pickup_location_id', 'idx_reservations_pickup_location_id');
            $table->index('destination_location_id', 'idx_reservations_destination_location_id');
            $table->index('driver_id', 'idx_reservations_driver_id');
            $table->index('vehicle_id', 'idx_reservations_vehicle_id');
            $table->index('recurrence_id', 'idx_reservations_recurrence_id');
            $table->index(['status', 'approval_type'], 'idx_reservations_status_approval_type');
            $table->index('validated_by_user_id', 'idx_reservations_validated_by_user_id');
            $table->index('cancelled_by_user_id', 'idx_reservations_cancelled_by_user_id');
            $table->index(['trip_date', 'driver_id'], 'idx_reservations_trip_date_driver_id');
            $table->index(['trip_date', 'vehicle_id'], 'idx_reservations_trip_date_vehicle_id');
            $table->index(['organization_id', 'status', 'trip_date'], 'idx_reservations_org_status_date');
            $table->index(['organization_id', 'activity_type'], 'idx_reservations_org_activity_type');

            $table->foreign('requested_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('pickup_location_id')
                ->references('id')
                ->on('locations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('destination_location_id')
                ->references('id')
                ->on('locations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('driver_id')
                ->references('id')
                ->on('drivers')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('recurrence_id')
                ->references('id')
                ->on('recurrences')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('validated_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('cancelled_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        DB::statement("
            ALTER TABLE {$prefix}reservations
            ADD CONSTRAINT {$constraint}
            CHECK (
                return_time > departure_time
                AND nb_adults >= 1
                AND nb_children >= 0
                AND (nb_children + nb_adults) >= 1
                AND (estimated_duration_min IS NULL OR estimated_duration_min > 0)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
