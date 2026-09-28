<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\PlanningExportRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\User;
use App\Models\Vehicle;
use App\Jobs\GeneratePlanningExportJob;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
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

    public function test_guest_cannot_access_planning_routes(): void
    {
        $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-16')
            ->assertUnauthorized();

        $this->getJson('/api/planning/export?start_date=2026-06-16&end_date=2026-06-16')
            ->assertUnauthorized();

        $this->postJson('/api/planning/exports', [
            'start_date' => '2026-06-16',
            'end_date' => '2026-06-16',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_without_permission_gets_forbidden_on_planning_routes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-16')
            ->assertForbidden();

        $this->get('/api/planning/export?start_date=2026-06-16&end_date=2026-06-16')
            ->assertForbidden();

        $this->postJson('/api/planning/exports', [
            'start_date' => '2026-06-16',
            'end_date' => '2026-06-16',
        ])->assertForbidden();
    }

    public function test_agent_can_request_planning_export_asynchronously(): void
    {
        if (! Schema::hasTable('planning_export_requests')) {
            $this->markTestSkipped('Table planning_export_requests absente dans le schema de test courant.');
        }

        Bus::fake();

        $agent = $this->userWithRole('agent_communal');
        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/planning/exports', [
            'start_date' => '2026-06-16',
            'end_date' => '2026-06-16',
            'statuses' => [ReservationStatus::CONFIRME->value],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.start_date', '2026-06-16')
            ->assertJsonPath('data.end_date', '2026-06-16');

        $requestId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $requestId);

        $this->assertDatabaseHas('planning_export_requests', [
            'id' => $requestId,
            'requested_by_user_id' => $agent->id,
            'status' => 'pending',
        ]);

        Bus::assertDispatched(GeneratePlanningExportJob::class, function (GeneratePlanningExportJob $job) use ($requestId): bool {
            return $job->planningExportRequestId === $requestId;
        });
    }

    public function test_user_cannot_read_other_user_planning_export_request(): void
    {
        if (! Schema::hasTable('planning_export_requests')) {
            $this->markTestSkipped('Table planning_export_requests absente dans le schema de test courant.');
        }

        $owner = $this->userWithRole('agent_communal');
        $other = $this->userWithRole('user');

        $request = PlanningExportRequest::query()->create([
            'requested_by_user_id' => $owner->id,
            'start_date' => '2026-06-16',
            'end_date' => '2026-06-16',
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($other);

        $this->getJson('/api/planning/exports/'.$request->id)
            ->assertNotFound();
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
            ->assertJsonPath('data.0.display.title', 'Ecole communale de Dinant - Sortie scolaire')
            ->assertJsonPath('data.0.display.time_label', '09:00 - 11:45')
            ->assertJsonPath('data.0.display.status_label', 'Confirmé')
            ->assertJsonPath('data.0.display.assignment_label', 'Affecte')
            ->assertJsonPath('data.0.display.color', 'success')
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

    public function test_planning_period_cannot_exceed_31_days(): void
    {
        $agent = $this->userWithRole('agent_communal');
        Sanctum::actingAs($agent);

        $this->getJson('/api/planning?start_date=2026-06-01&end_date=2026-07-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);
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

    public function test_agent_can_export_planning_as_excel_file(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();
        $driver = $this->driver('Alice', 'Martin');
        $vehicle = Vehicle::create([
            'licence_plate' => '1-XLS-001',
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
            'return_time' => '11:00:00',
            'trip_purpose' => 'Sortie piscine',
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->get('/api/planning/export?start_date=2026-06-16&end_date=2026-06-16');

        $response->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="planning-2026-06-16-2026-06-16.xls"');

        $this->assertStringContainsString('application/vnd.ms-excel', $response->headers->get('content-type'));
        $this->assertStringContainsString('Sortie piscine', $response->getContent());
        $this->assertStringContainsString('Alice Martin', $response->getContent());
        $this->assertStringContainsString('1-XLS-001', $response->getContent());
    }

    public function test_chauffeur_exports_only_his_assigned_planning(): void
    {
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();
        $firstDriver = $this->driver('Jean', 'Chauffeur');
        $secondDriver = $this->driver('Marc', 'Chauffeur');
        $vehicle = Vehicle::create([
            'licence_plate' => '1-XLS-002',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'driver_id' => $firstDriver->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '10:00:00',
            'trip_purpose' => 'Trajet visible chauffeur',
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
            'trip_purpose' => 'Trajet masque chauffeur',
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($firstDriver->user);

        $response = $this->get('/api/planning/export?start_date=2026-06-16&end_date=2026-06-16');

        $response->assertOk();
        $this->assertStringContainsString('Trajet visible chauffeur', $response->getContent());
        $this->assertStringNotContainsString('Trajet masque chauffeur', $response->getContent());
    }

    public function test_agent_can_filter_planning_by_vehicle(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();

        $firstVehicle = Vehicle::create([
            'licence_plate' => '1-FIL-001',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $secondVehicle = Vehicle::create([
            'licence_plate' => '1-FIL-002',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $visibleReservation = $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'vehicle_id' => $firstVehicle->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '10:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'vehicle_id' => $secondVehicle->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '11:00:00',
            'return_time' => '12:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson("/api/planning?start_date=2026-06-16&end_date=2026-06-16&vehicle_id={$firstVehicle->id}");

        $response->assertOk()
            ->assertJsonPath('filters.vehicle_id', $firstVehicle->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation_id', $visibleReservation->id)
            ->assertJsonPath('data.0.vehicle.id', $firstVehicle->id);
    }

    public function test_agent_can_filter_planning_by_driver(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();
        $firstDriver = $this->driver('Filtre', 'Chauffeur');
        $secondDriver = $this->driver('Autre', 'Chauffeur');

        $visibleReservation = $this->reservation([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'driver_id' => $firstDriver->id,
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
            'trip_date' => '2026-06-16',
            'departure_time' => '11:00:00',
            'return_time' => '12:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson("/api/planning?start_date=2026-06-16&end_date=2026-06-16&driver_id={$firstDriver->id}");

        $response->assertOk()
            ->assertJsonPath('filters.driver_id', $firstDriver->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation_id', $visibleReservation->id)
            ->assertJsonPath('data.0.driver.id', $firstDriver->id);
    }

    public function test_agent_can_filter_planning_by_organization(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $firstOrganization = $this->organization();
        $secondOrganization = Organization::create([
            'name' => 'ASBL Dinant Sport',
            'type' => OrganizationType::ASBL,
            'official_email' => 'sport@example.test',
            'is_priority' => false,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
        ]);
        [$pickup, $destination] = $this->locations();

        $visibleReservation = $this->reservation([
            'organization_id' => $firstOrganization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '10:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        $this->reservation([
            'organization_id' => $secondOrganization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '11:00:00',
            'return_time' => '12:00:00',
            'status' => ReservationStatus::CONFIRME,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson("/api/planning?start_date=2026-06-16&end_date=2026-06-16&organization_id={$firstOrganization->id}");

        $response->assertOk()
            ->assertJsonPath('filters.organization_id', $firstOrganization->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reservation_id', $visibleReservation->id)
            ->assertJsonPath('data.0.organization.id', $firstOrganization->id);
    }

    public function test_agent_can_request_availability_on_multiple_items_without_response_regression(): void
    {
        $agent = $this->userWithRole('agent_communal');
        $organization = $this->organization();
        [$pickup, $destination] = $this->locations();
        $driver = $this->driver('Charge', 'Test');
        $vehicle = Vehicle::create([
            'licence_plate' => '1-LOAD-001',
            'capacity' => 60,
            'is_active' => true,
        ]);

        foreach (range(0, 9) as $offset) {
            $this->reservation([
                'organization_id' => $organization->id,
                'pickup_location_id' => $pickup->id,
                'destination_location_id' => $destination->id,
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'trip_date' => '2026-06-'.str_pad((string) (16 + $offset), 2, '0', STR_PAD_LEFT),
                'departure_time' => '09:00:00',
                'return_time' => '10:00:00',
                'status' => ReservationStatus::CONFIRME,
            ]);
        }

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/planning?start_date=2026-06-16&end_date=2026-06-25&include_availability=1');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'reservation_id',
                    'availability' => [
                        'drivers' => ['available', 'unavailable'],
                        'vehicles' => ['available', 'unavailable'],
                    ],
                ]],
            ]);
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
