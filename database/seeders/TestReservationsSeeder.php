<?php

namespace Database\Seeders;

use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class TestReservationsSeeder extends Seeder
{
    public function run(): void
    {
        $requester = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();
        $agent = User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail();
        $school = Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail();
        $asbl = Organization::query()->where('name', 'ASBL Les Amis du Parc')->firstOrFail();
        $pickup = Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail();
        $destination = Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail();
        $driver = Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail();
        $vehicle = Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail();

        $reservations = [
            [
                'requested_by_user_id' => $requester->id,
                'organization_id' => $school->id,
                'pickup_location_id' => $pickup->id,
                'destination_location_id' => $destination->id,
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'trip_date' => '2026-06-16',
                'departure_time' => '09:00:00',
                'return_time' => '11:00:00',
                'nb_children' => 10,
                'nb_adults' => 1,
                'trip_purpose' => 'Sortie educative',
                'distance_km' => 12.00,
                'estimated_duration_min' => 30,
                'status' => ReservationStatus::CONFIRME,
                'approval_type' => ApprovalType::AUTO,
                'validated_by_user_id' => $agent->id,
                'validated_at' => now(),
            ],
            [
                'requested_by_user_id' => $requester->id,
                'organization_id' => $asbl->id,
                'pickup_location_id' => $pickup->id,
                'destination_location_id' => $destination->id,
                'trip_date' => '2026-06-18',
                'departure_time' => '08:30:00',
                'return_time' => '12:00:00',
                'nb_children' => 18,
                'nb_adults' => 2,
                'trip_purpose' => 'Activite culturelle',
                'distance_km' => 18.40,
                'estimated_duration_min' => 210,
                'status' => ReservationStatus::EN_ATTENTE,
                'approval_type' => ApprovalType::MANUEL,
            ],
        ];

        foreach ($reservations as $attributes) {
            Reservation::query()->updateOrCreate(
                [
                    'organization_id' => $attributes['organization_id'],
                    'trip_date' => $attributes['trip_date'],
                    'departure_time' => $attributes['departure_time'],
                ],
                $attributes
            );
        }
    }
}
