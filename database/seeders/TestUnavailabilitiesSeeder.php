<?php

namespace Database\Seeders;

use App\Enums\UnavailabilityReasonType;
use App\Models\Driver;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class TestUnavailabilitiesSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail();
        $driver = Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail();
        $vehicle = Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail();

        Unavailability::query()->updateOrCreate(
            [
                'reason_type' => UnavailabilityReasonType::DRIVER,
                'driver_id' => $driver->id,
                'start_datetime' => '2026-06-10 08:00:00',
            ],
            [
                'reason' => 'Maladie',
                'end_datetime' => '2026-06-10 18:00:00',
                'created_by_user_id' => $creator->id,
                'is_active' => true,
            ]
        );

        Unavailability::query()->updateOrCreate(
            [
                'reason_type' => UnavailabilityReasonType::VEHICLE,
                'vehicle_id' => $vehicle->id,
                'start_datetime' => '2026-06-12 08:00:00',
            ],
            [
                'reason' => 'Maintenance',
                'end_datetime' => '2026-06-12 18:00:00',
                'created_by_user_id' => $creator->id,
                'is_active' => true,
            ]
        );
    }
}
