<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CouncilDecisionGenerateDocumentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed([
            RolesAndPermissionsSeeder::class,
        ]);

        Carbon::setTestNow('2026-04-20 09:00:00');

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_agent_can_generate_document_for_approved_council_decision(): void
    {
        $agent = $this->userWithRole('agent_communal');

        $councilDecision = $this->createCouncilDecision(
            recordedByUser: $agent,
            decisionStatus: 'approuve'
        );

        Sanctum::actingAs($agent);

        $response = $this->postJson("/api/council-decisions/{$councilDecision->id}/generate-document");

        $response->assertCreated()
            ->assertJsonPath('data.document_type', DocumentType::DELIBERATION->value)
            ->assertJsonPath('data.template_key', 'deliberations.external')
            ->assertJsonPath('data.template_version', '1.0')
            ->assertJsonPath('data.is_official', true)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.version', 1);

        $documentId = $response->json('data.id');

        $document = Document::query()->findOrFail($documentId);

        $this->assertSame($councilDecision->reservation_id, $document->reservation_id);
        $this->assertSame($agent->id, $document->user_id);
        $this->assertSame(DocumentType::DELIBERATION, $document->document_type);
        $this->assertSame('deliberations.external', $document->template_key);
        $this->assertSame('1.0', $document->template_version);
        $this->assertTrue($document->is_official);
        $this->assertTrue($document->is_available);
        $this->assertSame(1, $document->version);
        $this->assertStringEndsWith('.pdf', $document->file_name);

        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_it_refuses_to_generate_document_when_decision_is_not_approved(): void
    {
        $agent = $this->userWithRole('agent_communal');

        $councilDecision = $this->createCouncilDecision(
            recordedByUser: $agent,
            decisionStatus: 'en_attente'
        );

        Sanctum::actingAs($agent);

        $this->postJson("/api/council-decisions/{$councilDecision->id}/generate-document")
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'La délibération ne peut être générée que pour une décision approuvée.',
            ]);

        $this->assertDatabaseMissing('documents', [
            'reservation_id' => $councilDecision->reservation_id,
            'document_type' => DocumentType::DELIBERATION->value,
        ]);
    }

    public function test_user_without_generate_permission_cannot_generate_document(): void
    {
        $user = $this->userWithRole('user');
        $agent = $this->userWithRole('agent_communal');

        $councilDecision = $this->createCouncilDecision(
            recordedByUser: $agent,
            decisionStatus: 'approuve'
        );

        Sanctum::actingAs($user);

        $this->postJson("/api/council-decisions/{$councilDecision->id}/generate-document")
            ->assertForbidden();

        $this->assertDatabaseMissing('documents', [
            'reservation_id' => $councilDecision->reservation_id,
            'document_type' => DocumentType::DELIBERATION->value,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createCouncilDecision(User $recordedByUser, string $decisionStatus): CouncilDecision
    {
        $requester = User::factory()->create();

        $organizationLocation = $this->createLocation('Rue de l’Association 12, 5500 Dinant');
        $pickupLocation = $this->createLocation('Place Albert 1er, 5500 Dinant');
        $destinationLocation = $this->createLocation('Rue de Namur 20, 5000 Namur');

        $organization = Organization::create([
            'name' => 'ASBL Solidarité Dinantaise',
            'type' => 'asbl',
            'official_email' => 'contact@asbl.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Responsable ASBL',
            'location_id' => $organizationLocation->id,
            'is_priority' => false,
            'annual_quota' => 5,
            'reservations_used_this_year' => 2,
        ]);

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie éducative',
            'activity_type' => 'standard',
            'distance_km' => 12,
            'estimated_duration_min' => 30,
            'status' => 'en_attente',
            'approval_type' => 'manuel',
        ]);

        return CouncilDecision::create([
            'reservation_id' => $reservation->id,
            'decision_status' => $decisionStatus,
            'decision_date' => '2026-04-20',
            'recorded_by_user_id' => $recordedByUser->id,
            'decision_reference' => 'COLL-2026-001',
            'validated_at' => $decisionStatus === 'approuve' ? now() : null,
            'notes' => 'Décision de test.',
        ]);
    }

    private function createLocation(string $formattedAddress): Location
    {
        return Location::create([
            'formatted_address' => $formattedAddress,
            'street' => 'Rue test',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'Belgique',
            'latitude' => 50.2600,
            'longitude' => 4.9100,
            'provider' => 'manual',
            'is_verified' => true,
        ]);
    }
}
