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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_db_accepts_return_time_after_17h00_service_will_enforce(): void
    {
        [$requester, $organization, $pickupLocation, $destinationLocation] = $this->reservationContext();

        $reservation = Reservation::create([
            'requested_by_user_id'    => $requester->id,
            'organization_id'         => $organization->id,
            'pickup_location_id'      => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date'               => '2026-06-10',
            'departure_time'          => '08:30:00',
            'return_time'             => '17:30:00',
            'nb_children'             => 10,
            'nb_adults'               => 2,
            'trip_purpose'            => 'Sortie scolaire',
            'status'                  => ReservationStatus::EN_ATTENTE,
            'approval_type'           => ApprovalType::MANUEL,
        ]);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    public function test_reservation_rejects_more_than_forty_passengers_plus_driver(): void
    {
        [$requester, $organization, $pickupLocation, $destinationLocation] = $this->reservationContext();

        $this->expectException(QueryException::class);

        Reservation::create([
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
    }

    public function test_reservation_db_accepts_distance_over_fifty_km_service_will_enforce(): void
{
    [$requester, $organization, $pickupLocation, $destinationLocation] = $this->reservationContext();

    $reservation = Reservation::create([
        'requested_by_user_id'    => $requester->id,
        'organization_id'         => $organization->id,
        'pickup_location_id'      => $pickupLocation->id,
        'destination_location_id' => $destinationLocation->id,
        'trip_date'               => '2026-06-12',
        'departure_time'          => '08:30:00',
        'return_time'             => '12:00:00',
        'nb_children'             => 20,
        'nb_adults'               => 2,
        'trip_purpose'            => 'Sortie pedagogique',
        'distance_km'             => 50.01,
        'status'                  => ReservationStatus::EN_ATTENTE,
        'approval_type'           => ApprovalType::MANUEL,
    ]);

    $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
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
            'correlation_id' => (string) Str::uuid(),
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
        $requester = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'direction@ecole.test',
            'phone_contact' => '082000000',
            'name_contact' => 'Direction',
            'is_priority' => true,
            'annual_quota' => 10,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);
        $pickupLocation = Location::create([
            'formatted_address' => 'Rue du Centre 1',
            'city' => 'Dinant',
            'country' => 'Belgique',
            'is_verified' => true,
        ]);
        $destinationLocation = Location::create([
            'formatted_address' => 'Avenue des Sports 2',
            'city' => 'Dinant',
            'country' => 'Belgique',
            'is_verified' => true,
        ]);

        return [$requester, $organization, $pickupLocation, $destinationLocation];
    }
}
