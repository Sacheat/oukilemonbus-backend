<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Location;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserOrganization;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModelDatabaseIntegrationTest extends TestCase
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

    public function test_models_persist_casts_and_relations_when_database_support_is_available(): void
    {
        $requester = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');

        $requester->organizations()->syncWithoutDetaching([$organization->id => [
            'relationship_type' => 'responsable',
            'is_default' => true,
        ]]);

        $pickupLocation = $this->seededLocation('seed-dinant-pickup');
        $destinationLocation = $this->seededLocation('seed-dinant-destination');

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-05-10',
            'departure_time' => '08:30:00',
            'return_time' => '12:00:00',
            'nb_children' => 12,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie scolaire au centre sportif',
            'distance_km' => 18.40,
            'estimated_duration_min' => 210,
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => ApprovalType::MANUEL,
        ]);

        $document = Document::create([
            'reservation_id' => $reservation->id,
            'user_id' => $requester->id,
            'document_type' => DocumentType::ATTESTATION,
            'file_name' => 'attestation-bus.pdf',
            'file_path' => 'private/documents/attestation-bus.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
            'generation_mode' => 'auto',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 2,
            'is_available' => true,
        ]);

        $delivery = NotificationDelivery::create([
            'event_id' => (string) Str::uuid(),
            'attempt_id' => (string) Str::uuid(),
            'attempt_number' => 1,
            'notification_type' => 'App\\Notifications\\ReservationApprovedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $requester->id,
            'reservation_id' => $reservation->id,
            'document_id' => $document->id,
            'channel' => 'mail',
            'recipient_email' => $requester->email,
            'status' => DeliveryStatus::SENT,
            'provider' => 'smtp',
            'provider_message_id' => 'message-123',
            'payload' => [
                'subject' => 'Votre reservation',
                'attachments' => ['attestation-bus.pdf'],
            ],
            'sent_at' => now(),
        ]);

        $pivot = $requester->organizations()->firstOrFail()->pivot;

        $this->assertInstanceOf(UserOrganization::class, $pivot);
        $this->assertTrue($pivot->is_default);
        $this->assertSame(ReservationStatus::EN_ATTENTE, $reservation->fresh()->status);
        $this->assertSame(DocumentType::ATTESTATION, $document->fresh()->document_type);
        $this->assertSame(DeliveryStatus::SENT, $delivery->fresh()->status);
        $this->assertSame($requester->id, $delivery->fresh()->notifiable->id);
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
