<?php

namespace Tests\Unit;

use App\Services\Documents\ContextBuilders\RouteSheetContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Enums\DocumentType;

class RouteSheetContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_empty_route_sheet_context_for_a_period_without_confirmed_reservations(): void
    {
        $builder = new RouteSheetContextBuilder();

        $context = $builder->buildForPeriod('2026-03-02', '2026-03-06');

        $this->assertIsArray($context);

        $this->assertArrayHasKey('document', $context);
        $this->assertArrayHasKey('period', $context);
        $this->assertArrayHasKey('rows', $context);

        $this->assertSame(DocumentType::FEUILLE_ROUTE->value, $context['document']['type']);
        $this->assertSame(DocumentType::FEUILLE_ROUTE->label(), $context['document']['label']);
        $this->assertSame('route_sheets.weekly', $context['document']['template_key']);
        $this->assertSame('1.0', $context['document']['template_version']);
        $this->assertArrayHasKey('generated_at', $context['document']);

        $this->assertSame('2026-03-02', $context['period']['start_date']);
        $this->assertSame('2026-03-06', $context['period']['end_date']);
        $this->assertSame('du 02/03/2026 au 06/03/2026', $context['period']['label']);

        $this->assertIsArray($context['rows']);
        $this->assertEmpty($context['rows']);
    }

    public function test_it_includes_confirmed_reservations_in_the_period(): void
    {
        $requester = User::factory()->create([
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);

        $organizationLocation = Location::create([
            'formatted_address' => 'Rue de l Ecole 1, 5500 Dinant',
            'street' => 'Rue de l Ecole',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $pickupLocation = Location::create([
            'formatted_address' => 'Ecole communale de Dinant, 5500 Dinant',
            'street' => 'Rue Grande',
            'street_number' => '10',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.261000,
            'longitude' => 4.912000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $destinationLocation = Location::create([
            'formatted_address' => 'Centre culturel de Dinant, 5500 Dinant',
            'street' => 'Rue du Centre',
            'street_number' => '5',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.263000,
            'longitude' => 4.914000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $organization = Organization::create([
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'ecole@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Mme Martin',
            'location_id' => $organizationLocation->id,
            'is_priority' => true,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-03',
            'departure_time' => '08:30:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie piscine',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 12.50,
            'estimated_duration_min' => 25,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $requester->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $builder = new RouteSheetContextBuilder();

        $context = $builder->buildForPeriod('2026-03-02', '2026-03-06');

        $this->assertCount(1, $context['rows']);

        $row = $context['rows'][0];

        $this->assertSame($reservation->id, $row['reservation_id']);
        $this->assertSame('03/03/2026', $row['date']);

        $this->assertSame('Ecole communale de Dinant', $row['beneficiary']['name']);
        $this->assertSame('Mme Martin', $row['beneficiary']['contact_name']);

        $this->assertSame(10, $row['passengers']['planned_children']);
        $this->assertSame(2, $row['passengers']['planned_adults']);
        $this->assertSame(12, $row['passengers']['planned_total']);
        $this->assertNull($row['passengers']['actual_total']);

        $this->assertSame('08h30', $row['pickup']['time']);
        $this->assertSame('Ecole communale de Dinant, 5500 Dinant', $row['pickup']['address']);

        $this->assertSame('Centre culturel de Dinant, 5500 Dinant', $row['destination']['label']);
        $this->assertSame('Centre culturel de Dinant, 5500 Dinant', $row['destination']['address']);
        $this->assertSame('50.26300000', $row['destination']['latitude']);
        $this->assertSame('4.91400000', $row['destination']['longitude']);
        $this->assertStringContainsString('google.com/maps', $row['destination']['navigation_url']);

        $this->assertSame('11h00', $row['return_pickup_time']);

        $this->assertSame('20/02/2026', $row['validation']['date']);
        $this->assertSame('reservation_validation', $row['validation']['source']);
    }

    public function test_it_builds_a_daily_route_sheet_context_for_a_vehicle(): void
    {
        config([
            'route_sheets.transporter.name' => 'Commune de Dinant - Service mobilite',
            'route_sheets.transporter.identifier' => 'Commune de Dinant',
            'route_sheets.transporter.license_number' => 'LIC-TEST',
        ]);

        $driverUser = User::factory()->create([
            'first_name' => 'Bruno',
            'last_name' => 'Chauffeur',
            'phone' => '082100002',
        ]);
        $requester = User::factory()->create();

        $driver = Driver::create([
            'user_id' => $driverUser->id,
            'licence_number' => 'DRV-001',
            'licence_expiry_date' => '2030-01-01',
            'is_active' => true,
        ]);

        $vehicle = Vehicle::create([
            'licence_plate' => '1-VJX-649',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $pickupLocation = Location::create([
            'formatted_address' => 'Place Albert 1er, 5500 Dinant',
            'street' => 'Place Albert 1er',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $destinationLocation = Location::create([
            'formatted_address' => 'Centre sportif, 5500 Dinant',
            'street' => 'Rue du Sport',
            'street_number' => '2',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.270000,
            'longitude' => 4.920000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $organization = Organization::create([
            'name' => 'ASBL Les Amis du Parc',
            'type' => OrganizationType::ASBL,
            'official_email' => 'contact@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Mme Contact',
            'location_id' => $pickupLocation->id,
            'is_priority' => false,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => '2026-06-18',
            'departure_time' => '08:30:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie sportive',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 12.50,
            'estimated_duration_min' => 25,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
        ]);

        $context = (new RouteSheetContextBuilder())->buildForServiceDateVehicle('2026-06-18', $vehicle->id);

        $this->assertSame('route_sheets.daily', $context['document']['template_key']);
        $this->assertSame('2026-06-18', $context['service']['date']);
        $this->assertSame('1-VJX-649', $context['service']['vehicle']['licence_plate']);
        $this->assertSame('Commune de Dinant - Service mobilite', $context['service']['transporter']['name']);
        $this->assertSame('LIC-TEST', $context['service']['transporter']['license_number']);
        $this->assertSame(12, $context['service']['total_passengers']);
        $this->assertSame(25.0, $context['service']['total_distance_km']); // aller-retour : 12,5 km × 2
        $this->assertSame('2029-06-18', $context['service']['retention_until']);
        $this->assertSame('Bruno Chauffeur', $context['service']['drivers'][0]['name']);
        $this->assertSame('DRV-001', $context['service']['drivers'][0]['licence_number']);

        $this->assertCount(1, $context['rows']);
        $this->assertSame($reservation->id, $context['rows'][0]['reservation_id']);
        $this->assertSame('ASBL Les Amis du Parc', $context['rows'][0]['order_giver']['name']);
        $this->assertSame('08h30', $context['rows'][0]['departure']['time']);
        $this->assertSame('Centre sportif, 5500 Dinant', $context['rows'][0]['destination']['place']);
        $this->assertSame('Place Albert 1er, 5500 Dinant - Centre sportif, 5500 Dinant - Place Albert 1er, 5500 Dinant', $context['rows'][0]['main_route']);
    }

    public function test_it_orders_confirmed_reservations_by_trip_date_then_departure_time(): void
    {
        $requester = User::factory()->create([
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);

        $organizationLocation = Location::create([
            'formatted_address' => 'Rue de l Ecole 1, 5500 Dinant',
            'street' => 'Rue de l Ecole',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $pickupLocation = Location::create([
            'formatted_address' => 'Ecole communale de Dinant, 5500 Dinant',
            'street' => 'Rue Grande',
            'street_number' => '10',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.261000,
            'longitude' => 4.912000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $destinationLocation = Location::create([
            'formatted_address' => 'Centre culturel de Dinant, 5500 Dinant',
            'street' => 'Rue du Centre',
            'street_number' => '5',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.263000,
            'longitude' => 4.914000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $organization = Organization::create([
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'ecole@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Mme Martin',
            'location_id' => $organizationLocation->id,
            'is_priority' => true,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        $secondReservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-04',
            'departure_time' => '08:00:00',
            'return_time' => '10:00:00',
            'nb_children' => 8,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie bibliothèque',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 8.00,
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $requester->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $firstReservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-03',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie piscine',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 12.50,
            'estimated_duration_min' => 25,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $requester->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $thirdReservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-04',
            'departure_time' => '13:30:00',
            'return_time' => '15:00:00',
            'nb_children' => 6,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie musée',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 10.00,
            'estimated_duration_min' => 22,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $requester->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $builder = new RouteSheetContextBuilder();

        $context = $builder->buildForPeriod('2026-03-02', '2026-03-06');

        $this->assertCount(3, $context['rows']);

        $this->assertSame($firstReservation->id, $context['rows'][0]['reservation_id']);
        $this->assertSame($secondReservation->id, $context['rows'][1]['reservation_id']);
        $this->assertSame($thirdReservation->id, $context['rows'][2]['reservation_id']);

        $this->assertSame('03/03/2026', $context['rows'][0]['date']);
        $this->assertSame('09h00', $context['rows'][0]['pickup']['time']);

        $this->assertSame('04/03/2026', $context['rows'][1]['date']);
        $this->assertSame('08h00', $context['rows'][1]['pickup']['time']);

        $this->assertSame('04/03/2026', $context['rows'][2]['date']);
        $this->assertSame('13h30', $context['rows'][2]['pickup']['time']);
    }
}
