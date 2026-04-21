<?php

namespace Tests\Feature;

use App\Enums\AssignmentType;
use App\Enums\CouncilDecisionStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Enums\UnavailabilityReasonType;
use App\Models\CouncilDecision;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationAssignmentHistory;
use App\Models\ReservationStatusHistory;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlanningAndHistoryDatabaseRulesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
            TestOrganizationsSeeder::class,
            TestFleetSeeder::class,
        ]);
    }

    public function test_driver_profile_is_unique_per_user(): void
    {
        $user = User::factory()->create();

        Driver::create([
            'user_id' => $user->id,
            'licence_number' => 'LIC-001',
            'licence_expiry_date' => '2027-12-31',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Driver::create([
            'user_id' => $user->id,
            'licence_number' => 'LIC-002',
            'licence_expiry_date' => '2028-12-31',
            'is_active' => true,
        ]);
    }

    public function test_vehicle_licence_plate_must_be_unique(): void
    {
        Vehicle::create([
            'licence_plate' => '1-ABC-123',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Vehicle::create([
            'licence_plate' => '1-ABC-123',
            'capacity' => 41,
            'is_active' => true,
        ]);
    }

    public function test_only_one_council_decision_can_exist_per_reservation(): void
    {
        [$reservation, $actor] = $this->reservationWithActor();

        CouncilDecision::create([
            'reservation_id' => $reservation->id,
            'decision_status' => CouncilDecisionStatus::EN_ATTENTE,
            'recorded_by_user_id' => $actor->id,
        ]);

        $this->expectException(QueryException::class);

        CouncilDecision::create([
            'reservation_id' => $reservation->id,
            'decision_status' => CouncilDecisionStatus::APPROUVE,
            'recorded_by_user_id' => $actor->id,
        ]);
    }

    public function test_deleting_a_reservation_cascades_its_decision_and_histories(): void
    {
        [$reservation, $actor] = $this->reservationWithActor();
        [$driver, $vehicle] = $this->additionalDriverAndVehicle();

        $decision = CouncilDecision::create([
            'reservation_id' => $reservation->id,
            'decision_status' => CouncilDecisionStatus::APPROUVE,
            'decision_date' => '2026-06-20',
            'recorded_by_user_id' => $actor->id,
        ]);

        $statusHistory = ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            'old_status' => ReservationStatus::EN_ATTENTE,
            'new_status' => ReservationStatus::APPROUVE,
            'changed_by_user_id' => $actor->id,
            'reason' => 'Validation du college',
            'created_at' => now(),
        ]);

        $assignmentHistory = ReservationAssignmentHistory::create([
            'reservation_id' => $reservation->id,
            'assignment_type' => AssignmentType::DRIVER,
            'new_driver_id' => $driver->id,
            'new_vehicle_id' => $vehicle->id,
            'changed_by_user_id' => $actor->id,
            'reason' => 'Affectation initiale',
            'created_at' => now(),
        ]);

        $reservation->delete();

        $this->assertDatabaseMissing('council_decisions', ['id' => $decision->id]);
        $this->assertDatabaseMissing('reservation_status_history', ['id' => $statusHistory->id]);
        $this->assertDatabaseMissing('reservation_assignment_history', ['id' => $assignmentHistory->id]);
    }

    public function test_deleting_a_driver_nulls_driver_references_but_keeps_history_row(): void
    {
        [$reservation, $actor] = $this->reservationWithActor();
        [$driver, $vehicle] = $this->additionalDriverAndVehicle();

        $reservation->update(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);

        $history = ReservationAssignmentHistory::create([
            'reservation_id' => $reservation->id,
            'assignment_type' => AssignmentType::DRIVER,
            'old_driver_id' => $driver->id,
            'new_driver_id' => $driver->id,
            'changed_by_user_id' => $actor->id,
            'reason' => 'Historique chauffeur',
            'created_at' => now(),
        ]);

        $driver->delete();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'driver_id' => null,
        ]);
        $this->assertDatabaseHas('reservation_assignment_history', [
            'id' => $history->id,
            'old_driver_id' => null,
            'new_driver_id' => null,
        ]);
    }

    public function test_deleting_a_vehicle_nulls_vehicle_references_but_keeps_history_row(): void
    {
        [$reservation, $actor] = $this->reservationWithActor();
        [$driver, $vehicle] = $this->additionalDriverAndVehicle();

        $reservation->update(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);

        $history = ReservationAssignmentHistory::create([
            'reservation_id' => $reservation->id,
            'assignment_type' => AssignmentType::VEHICLE,
            'old_vehicle_id' => $vehicle->id,
            'new_vehicle_id' => $vehicle->id,
            'changed_by_user_id' => $actor->id,
            'reason' => 'Historique vehicule',
            'created_at' => now(),
        ]);

        $vehicle->delete();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'vehicle_id' => null,
        ]);
        $this->assertDatabaseHas('reservation_assignment_history', [
            'id' => $history->id,
            'old_vehicle_id' => null,
            'new_vehicle_id' => null,
        ]);
    }

    public function test_driver_with_unavailability_cannot_be_deleted_because_of_restrict_on_delete(): void
    {
        $actor = User::factory()->create();
        [$driver] = $this->additionalDriverAndVehicle();

        Unavailability::create([
            'reason_type' => UnavailabilityReasonType::DRIVER,
            'reason' => 'Conge',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-21 08:00:00',
            'end_datetime' => '2026-06-21 12:00:00',
            'created_by_user_id' => $actor->id,
        ]);

        $this->expectException(QueryException::class);

        $driver->delete();
    }

    private function reservationWithActor(): array
    {
        $actor = $this->seededUser('agent.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');
        $pickup = $this->seededLocation('seed-dinant-pickup');
        $destination = $this->seededLocation('seed-dinant-destination');

        $reservation = Reservation::create([
            'requested_by_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-20',
            'departure_time' => '08:30:00',
            'return_time' => '12:00:00',
            'nb_children' => 18,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie educative',
            'distance_km' => 23.40,
            'estimated_duration_min' => 180,
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => 'manuel',
        ]);

        return [$reservation, $actor];
    }

    private function additionalDriverAndVehicle(): array
    {
        $user = User::factory()->create();

        $driver = Driver::create([
            'user_id' => $user->id,
            'licence_number' => 'LIC-'.$user->id,
            'licence_expiry_date' => '2027-12-31',
            'is_active' => true,
        ]);

        $vehicle = Vehicle::create([
            'licence_plate' => '1-XYZ-'.$user->id,
            'capacity' => 41,
            'is_active' => true,
        ]);

        return [$driver, $vehicle];
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededOrganization(string $name): Organization
    {
        return Organization::query()->where('name', $name)->firstOrFail();
    }

    private function seededLocation(string $placeId): Location
    {
        return Location::query()->where('place_id', $placeId)->firstOrFail();
    }
}
