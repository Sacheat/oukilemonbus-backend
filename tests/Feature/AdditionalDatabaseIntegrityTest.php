<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Document;
use App\Models\Location;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\Recurrence;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserOrgRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdditionalDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_rejects_invalid_version_and_negative_file_size(): void
    {
        [$reservation, $user] = $this->reservationAndUser();

        $this->expectException(QueryException::class);

        Document::create([
            'reservation_id' => $reservation->id,
            'user_id' => $user->id,
            'document_type' => DocumentType::PLANNING,
            'file_name' => 'planning.pdf',
            'file_path' => 'private/documents/planning.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => -1,
            'generation_mode' => 'auto',
            'version' => 0,
            'is_available' => true,
        ]);
    }

    public function test_deleting_reservation_and_user_nulls_document_foreign_keys(): void
    {
        [$reservation, $user] = $this->reservationAndUser();

        $document = Document::create([
            'reservation_id' => $reservation->id,
            'user_id' => $user->id,
            'document_type' => DocumentType::ATTESTATION,
            'file_name' => 'attestation.pdf',
            'file_path' => 'private/documents/attestation.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'generation_mode' => 'auto',
            'version' => 1,
            'is_available' => true,
        ]);

        $reservation->delete();
        $user->delete();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'reservation_id' => null,
            'user_id' => null,
        ]);
    }

    public function test_notification_delivery_requires_unique_correlation_id(): void
    {
        [$reservation, $user] = $this->reservationAndUser();
        $correlationId = (string) Str::uuid();

        NotificationDelivery::create([
            'correlation_id' => $correlationId,
            'notification_type' => 'App\\Notifications\\ReservationPendingNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'reservation_id' => $reservation->id,
            'channel' => 'mail',
            'recipient_email' => $user->email,
            'status' => 'pending',
        ]);

        $this->expectException(QueryException::class);

        NotificationDelivery::create([
            'correlation_id' => $correlationId,
            'notification_type' => 'App\\Notifications\\ReservationPendingNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'reservation_id' => $reservation->id,
            'channel' => 'mail',
            'recipient_email' => $user->email,
            'status' => 'pending',
        ]);
    }

    public function test_deleting_reservation_and_document_nulls_notification_delivery_foreign_keys(): void
    {
        [$reservation, $user] = $this->reservationAndUser();
        $document = Document::create([
            'reservation_id' => $reservation->id,
            'user_id' => $user->id,
            'document_type' => DocumentType::ATTESTATION,
            'file_name' => 'attestation.pdf',
            'file_path' => 'private/documents/attestation.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'generation_mode' => 'auto',
            'version' => 1,
            'is_available' => true,
        ]);

        $delivery = NotificationDelivery::create([
            'correlation_id' => (string) Str::uuid(),
            'notification_type' => 'App\\Notifications\\ReservationPendingNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'reservation_id' => $reservation->id,
            'document_id' => $document->id,
            'channel' => 'mail',
            'recipient_email' => $user->email,
            'status' => 'pending',
        ]);

        $reservation->delete();
        $document->delete();

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->id,
            'reservation_id' => null,
            'document_id' => null,
        ]);
    }

    public function test_recurrence_rejects_invalid_values(): void
    {
        $this->expectException(QueryException::class);

        Recurrence::create([
            'frequency' => 'hebdomadaire',
            'interval_value' => 0,
            'start_date' => '2026-06-01',
            'end_date' => '2026-05-01',
            'occurrence_count' => 0,
            'is_active' => true,
        ]);
    }

    public function test_deleting_recurrence_nulls_reservation_recurrence_id(): void
    {
        [$reservation] = $this->reservationAndUser();

        $recurrence = Recurrence::create([
            'frequency' => 'hebdomadaire',
            'interval_value' => 7,
            'days_of_week' => '1,3',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'occurrence_count' => 4,
            'is_active' => true,
        ]);

        $reservation->update(['recurrence_id' => $recurrence->id]);
        $recurrence->delete();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'recurrence_id' => null,
        ]);
    }

    public function test_user_org_request_rejects_confidence_note_outside_range(): void
    {
        [$organization, $requester] = $this->organizationAndUser();

        $this->expectException(QueryException::class);

        UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'responsable',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 101,
        ]);
    }

    public function test_deleting_reviewer_nulls_reviewed_by_on_request_tables(): void
    {
        [$organization, $requester] = $this->organizationAndUser();
        $reviewer = User::factory()->create();
        $location = Location::create([
            'formatted_address' => 'Rue des Ecoles 1',
            'city' => 'Dinant',
            'country' => 'Belgique',
            'is_verified' => true,
        ]);

        $userOrgRequest = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'responsable',
            'status' => RequestStatus::APPROUVE,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $orgRegistrationRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Association sportive',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'contact@asbl.test',
            'location_id' => $location->id,
            'status' => RequestStatus::APPROUVE,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $reviewer->delete();

        $this->assertDatabaseHas('user_org_requests', [
            'request_id' => $userOrgRequest->request_id,
            'reviewed_by_user_id' => null,
        ]);
        $this->assertDatabaseHas('org_registration_requests', [
            'id' => $orgRegistrationRequest->id,
            'reviewed_by_user_id' => null,
        ]);
    }

    public function test_deleting_location_nulls_org_registration_request_location(): void
    {
        $requester = User::factory()->create();
        $location = Location::create([
            'formatted_address' => 'Rue des Ecoles 1',
            'city' => 'Dinant',
            'country' => 'Belgique',
            'is_verified' => true,
        ]);

        $request = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Association sportive',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'contact@asbl.test',
            'location_id' => $location->id,
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $location->delete();

        $this->assertDatabaseHas('org_registration_requests', [
            'id' => $request->id,
            'location_id' => null,
        ]);
    }

    public function test_deleting_requested_user_or_organization_is_restricted_when_request_exists(): void
    {
        [$organization, $requester] = $this->organizationAndUser();

        UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'responsable',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        try {
            $requester->delete();
            $this->fail('Deleting requester should have been restricted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        $organization->delete();
    }

    public function test_user_organization_rejects_duplicate_pair_and_cascades_on_user_delete(): void
    {
        [$organization, $user] = $this->organizationAndUser();

        $user->organizations()->attach($organization->id, [
            'relationship_type' => 'responsable',
            'is_default' => true,
        ]);

        try {
            $user->organizations()->attach($organization->id, [
                'relationship_type' => 'responsable',
                'is_default' => true,
            ]);
            $this->fail('Duplicate pivot pair should have been rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $user->delete();

        $this->assertDatabaseMissing('user_organization', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);
    }

    private function reservationAndUser(): array
    {
        $user = User::factory()->create();
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
        $pickup = Location::create([
            'formatted_address' => 'Rue du Centre 1',
            'city' => 'Dinant',
            'country' => 'Belgique',
            'is_verified' => true,
        ]);
        $destination = Location::create([
            'formatted_address' => 'Avenue des Sports 2',
            'city' => 'Dinant',
            'country' => 'Belgique',
            'is_verified' => true,
        ]);

        $reservation = Reservation::create([
            'requested_by_user_id' => $user->id,
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
            'status' => 'en_attente',
            'approval_type' => 'manuel',
        ]);

        return [$reservation, $user];
    }

    private function organizationAndUser(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Association locale',
            'type' => OrganizationType::ASBL,
            'official_email' => 'contact@association.test',
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);

        return [$organization, $user];
    }
}
