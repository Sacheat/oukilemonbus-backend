<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\DeliveryStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Enums\UnavailabilityReasonType;
use App\Models\Location;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Unavailability;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationBusinessRulesTest extends TestCase
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
        ]);
    }

    public function test_reservation_allows_a_return_time_after_17h00_at_database_level(): void
    {
        [$requester, $organization, $pickupLocation, $destinationLocation] = $this->reservationContext();

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-06-10',
            'departure_time' => '08:30:00',
            'return_time' => '17:30:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie scolaire',
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => ApprovalType::MANUEL,
        ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'return_time' => '17:30:00',
        ]);
    }

    public function test_reservation_allows_passenger_count_above_vehicle_capacity_at_database_level(): void
    {
        [$requester, $organization, $pickupLocation, $destinationLocation] = $this->reservationContext();

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-06-11',
            'departure_time' => '08:30:00',
            'return_time' => '12:00:00',
            'nb_children' => 39,
            'nb_adults' => 2,
            'trip_purpose' => 'Activite culturelle',
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => ApprovalType::MANUEL,
        ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'nb_children' => 39,
            'nb_adults' => 2,
        ]);
    }

    public function test_reservation_allows_distance_over_fifty_kilometres_at_database_level(): void
    {
        [$requester, $organization, $pickupLocation, $destinationLocation] = $this->reservationContext();

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-06-12',
            'departure_time' => '08:30:00',
            'return_time' => '12:00:00',
            'nb_children' => 20,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie pedagogique',
            'distance_km' => 50.01,
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => ApprovalType::MANUEL,
        ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'distance_km' => 50.01,
        ]);
    }

    public function test_organization_rejects_negative_quota(): void
    {
        $this->expectException(QueryException::class);

        Organization::create([
            'name' => 'Association locale',
            'type' => OrganizationType::ASBL,
            'official_email' => 'contact@association.test',
            'annual_quota' => -1,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);
    }

    public function test_unavailability_rejects_an_end_before_start(): void
    {
        $creator = User::factory()->create();

        $this->expectException(QueryException::class);

        Unavailability::create([
            'reason_type' => UnavailabilityReasonType::VEHICLE,
            'reason' => 'Maintenance',
            'start_datetime' => '2026-06-15 12:00:00',
            'end_datetime' => '2026-06-15 10:00:00',
            'created_by_user_id' => $creator->id,
        ]);
    }

    public function test_notification_delivery_rejects_inconsistent_status_timestamps(): void
    {
        $requester = User::factory()->create();

        $this->expectException(QueryException::class);

        NotificationDelivery::create([
            'event_id' => (string) Str::uuid(),
            'attempt_id' => (string) Str::uuid(),
            'attempt_number' => 1,
            'notification_type' => 'App\\Notifications\\ReservationPendingNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $requester->id,
            'channel' => 'mail',
            'recipient_email' => $requester->email,
            'status' => DeliveryStatus::PENDING,
            'provider' => 'smtp',
            'sent_at' => now(),
        ]);
    }

    public function test_notification_delivery_rejects_invalid_attempt_number(): void
    {
        $requester = User::factory()->create();

        $this->expectException(QueryException::class);

        NotificationDelivery::create([
            'event_id' => (string) Str::uuid(),
            'attempt_id' => (string) Str::uuid(),
            'attempt_number' => 0,
            'notification_type' => 'App\\Notifications\\ReservationPendingNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $requester->id,
            'channel' => 'mail',
            'recipient_email' => $requester->email,
            'status' => DeliveryStatus::PENDING,
            'provider' => 'smtp',
        ]);
    }

    public function test_valid_reservation_is_persisted_successfully(): void
    {
        [$requester, $organization, $pickupLocation, $destinationLocation] = $this->reservationContext();

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '08:30:00',
            'return_time' => '12:00:00',
            'nb_children' => 18,
            'nb_adults' => 2,
            'trip_purpose' => 'Visite educative',
            'distance_km' => 23.40,
            'estimated_duration_min' => 180,
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => ApprovalType::MANUEL,
        ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'status' => ReservationStatus::EN_ATTENTE->value,
            'approval_type' => ApprovalType::MANUEL->value,
        ]);
    }

    private function reservationContext(): array
    {
        $requester = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');
        $pickupLocation = $this->seededLocation('seed-dinant-pickup');
        $destinationLocation = $this->seededLocation('seed-dinant-destination');

        return [$requester, $organization, $pickupLocation, $destinationLocation];
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
