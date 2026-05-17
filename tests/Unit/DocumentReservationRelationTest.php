<?php

namespace Tests\Unit;

use App\Enums\ApprovalType;
use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentReservationRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_can_have_ordered_route_sheet_reservations(): void
    {
        $user = User::factory()->create();

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

        $firstReservation = Reservation::create([
            'requested_by_user_id' => $user->id,
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
            'validated_by_user_id' => $user->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $secondReservation = Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-03',
            'departure_time' => '13:30:00',
            'return_time' => '15:00:00',
            'nb_children' => 8,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie musée',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 10.00,
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $user->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $document = Document::create([
            'reservation_id' => null,
            'user_id' => $user->id,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'route-sheet-test.pdf',
            'file_path' => 'documents/route-sheets/route-sheet-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'checksum' => 'fake-checksum',
            'generation_mode' => 'auto',
            'generation_context' => [],
            'template_key' => 'route_sheets.weekly',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 1,
            'is_available' => true,
        ]);

        $document->routeSheetReservations()->attach($secondReservation->id, [
            'position' => 2,
        ]);

        $document->routeSheetReservations()->attach($firstReservation->id, [
            'position' => 1,
        ]);

        $reservations = $document->routeSheetReservations()->get();

        $this->assertCount(2, $reservations);

        $this->assertSame($firstReservation->id, $reservations[0]->id);
        $this->assertSame(1, $reservations[0]->pivot->position);

        $this->assertSame($secondReservation->id, $reservations[1]->id);
        $this->assertSame(2, $reservations[1]->pivot->position);
    }
}
