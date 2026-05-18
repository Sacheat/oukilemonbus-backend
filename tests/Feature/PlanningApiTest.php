<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlanningApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['reservations.duration_margin_min' => 15]);
    }

    public function test_agent_can_view_planning_with_availability_details(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();
        $driver = $this->driver('Alice', 'Martin');
        $vehicle = Vehicle::create([
            'licence_plate' => '1-ABC-123',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $reservation = $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'estimated_duration_min' => 30,
            'status' => ReservationStatus::CONFIRME,
        ]);

        // Reservation hors periode : elle ne doit pas apparaitre dans le planning demande.
        $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-20',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'estimated_duration_min' => 30,
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-17&include_availability=1');

        $response->assertOk()
            ->assertJsonPath('period.start_date', '2026-06-16')
            ->assertJsonPath('period.end_date', '2026-06-17')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation_id', $reservation->id)
            ->assertJsonPath('data.0.occupation_start', '2026-06-16 09:00:00')
            ->assertJsonPath('data.0.occupation_end', '2026-06-16 11:45:00')
            ->assertJsonPath('data.0.is_fully_assigned', true)
            ->assertJsonStructure([
                'data' => [[
                    'availability' => [
                        'drivers' => ['available', 'unavailable'],
                        'vehicles' => ['available', 'unavailable'],
                    ],
                ]],
            ]);
    }

    public function test_start_date_and_end_date_are_required(): void
    {
        $agent = $this->userWithRole('agent_communal');
        Sanctum::actingAs($agent);

        $this->getJson('/api/planning')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    public function test_chauffeur_only_sees_his_assigned_reservations(): void
    {
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();
        $firstDriver = $this->driver('Jean', 'Chauffeur');
        $secondDriver = $this->driver('Marc', 'Chauffeur');
        $vehicle = Vehicle::create([
            'licence_plate' => '1-DEF-456',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $visibleReservation = $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'driver_id' => $firstDriver->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '10:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'driver_id' => $secondDriver->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '11:00:00',
            'return_time' => '12:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($firstDriver->user);

        $response = $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-16');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation_id', $visibleReservation->id);
    }

    public function test_chauffeur_does_not_receive_availability_details(): void
    {
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();
        $driver = $this->driver('Paul', 'Chauffeur');
        $vehicle = Vehicle::create([
            'licence_plate' => '1-GHI-789',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '10:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($driver->user);

        $response = $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-16&include_availability=1');

        $response->assertOk();
        $this->assertArrayNotHasKey('availability', $response->json('data.0'));
    }


    public function test_agent_can_filter_planning_by_status(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();

        $confirmedReservation = $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '10:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '11:00:00',
            'return_time' => '12:00:00',
            'status' => ReservationStatus::APPROUVE,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-16&statuses[]=confirme');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation_id', $confirmedReservation->id)
            ->assertJsonPath('data.0.status', ReservationStatus::CONFIRME->value);
    }

    public function test_agent_can_view_conflicts_in_planning(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();

        $reservation = $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '10:00:00',
            'status' => ReservationStatus::EN_CONFLIT,
        ]);

        ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'type' => ReservationConflictType::SCHEDULE_OVERLAP,
            'message' => 'Chevauchement avec une autre reservation.',
            'is_resolved' => false,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-16');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation_id', $reservation->id)
            ->assertJsonPath('data.0.conflicts.0.type', ReservationConflictType::SCHEDULE_OVERLAP->value)
            ->assertJsonPath('data.0.conflicts.0.message', 'Chevauchement avec une autre reservation.')
            ->assertJsonPath('data.0.conflicts.0.is_resolved', false);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function driver(string $firstName, string $lastName): Driver
    {
        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $user->assignRole('chauffeur');

        return Driver::create([
            'user_id' => $user->id,
            'licence_number' => fake()->unique()->bothify('LIC-####'),
            'licence_expiry_date' => '2028-12-31',
            'is_active' => true,
        ]);
    }

    private function organization(): Organization
    {
        return Organization::create([
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'ecole@example.test',
            'is_priority' => true,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
        ]);
    }

    private function locations(): array
    {
        return [
            Location::create([
                'formatted_address' => 'Rue Grande 1, 5500 Dinant',
                'city' => 'Dinant',
                'country' => 'Belgique',
                'latitude' => 50.2600,
                'longitude' => 4.9120,
                'is_verified' => true,
            ]),
            Location::create([
                'formatted_address' => 'Rue du College 2, 5500 Dinant',
                'city' => 'Dinant',
                'country' => 'Belgique',
                'latitude' => 50.2610,
                'longitude' => 4.9130,
                'is_verified' => true,
            ]),
        ];
    }

    private function reservation(array $overrides = []): Reservation
    {
        $requester = User::factory()->create();

        return Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $overrides['organization_id'],
            'pickup_location_id' => $overrides['pickup_location_id'],
            'destination_location_id' => $overrides['destination_location_id'],
            'driver_id' => $overrides['driver_id'] ?? null,
            'vehicle_id' => $overrides['vehicle_id'] ?? null,
            'trip_date' => $overrides['trip_date'] ?? '2026-06-16',
            'departure_time' => $overrides['departure_time'] ?? '09:00:00',
            'return_time' => $overrides['return_time'] ?? '11:00:00',
            'nb_children' => $overrides['nb_children'] ?? 10,
            'nb_adults' => $overrides['nb_adults'] ?? 1,
            'trip_purpose' => $overrides['trip_purpose'] ?? 'Sortie scolaire',
            'activity_type' => $overrides['activity_type'] ?? ReservationActivityType::STANDARD,
            'distance_km' => $overrides['distance_km'] ?? 12.5,
            'estimated_duration_min' => $overrides['estimated_duration_min'] ?? 30,
            'status' => $overrides['status'] ?? ReservationStatus::CONFIRME,
            'approval_type' => $overrides['approval_type'] ?? ApprovalType::AUTO,
        ]);
    }
}
