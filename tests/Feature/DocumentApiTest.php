<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\DocumentAccessToken;
use App\Models\Driver;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests d'API pour la gestion des documents générés
 * - Vérifie que les endpoints de listing, de détail et de téléchargement des documents appliquent correctement les règles d'accès définies dans DocumentPolicy et DocumentAccessService
 * - S'assure que les utilisateurs ne peuvent voir ou télécharger que les documents auxquels ils ont droit, et que les informations sensibles comme le file_path ne sont pas exposées dans les réponses API
 */
class DocumentApiTest extends TestCase
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

    public function test_admin_can_list_documents_and_file_path_is_not_exposed(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('user');

        $document = $this->createStoredDocument($owner);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.document_type', DocumentType::JUSTIFICATIF->value)
            ->assertJsonPath('data.0.document_type_label', DocumentType::JUSTIFICATIF->label())
            ->assertJsonPath('data.0.generation_context.source', 'test');

        $this->assertArrayNotHasKey('file_path', $response->json('data.0'));
    }

    public function test_related_user_can_show_document_and_file_path_is_not_exposed(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/documents/{$document->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.file_name', 'justificatif-test.pdf');

        $this->assertArrayNotHasKey('file_path', $response->json('data'));
        $this->assertArrayNotHasKey('generation_context', $response->json('data'));
    }

    public function test_unrelated_user_cannot_show_document(): void
    {
        $owner = $this->userWithRole('user');
        $stranger = $this->userWithRole('user');

        $document = $this->createStoredDocument($owner);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_normal_user_cannot_show_unavailable_document(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user, isAvailable: false);

        Sanctum::actingAs($user);

        $this->getJson("/api/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_admin_can_show_unavailable_document(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('user');

        $document = $this->createStoredDocument($owner, isAvailable: false);

        Sanctum::actingAs($admin);

        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.is_available', false);
    }

    public function test_user_can_download_own_available_document(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user);

        Sanctum::actingAs($user);

        $response = $this->get("/api/documents/{$document->id}/download");

        $response->assertOk();
        $response->assertHeader('Content-Disposition');

        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_download_returns_not_found_when_file_is_missing(): void
    {
        $user = $this->userWithRole('user');

        $document = Document::create([
            'user_id' => $user->id,
            'document_type' => DocumentType::JUSTIFICATIF,
            'file_name' => 'fichier-manquant.pdf',
            'file_path' => 'documents/tests/fichier-manquant.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'checksum' => hash('sha256', 'missing'),
            'generation_mode' => 'auto',
            'generation_context' => [
                'source' => 'test',
            ],
            'template_key' => 'route_sheets.default',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 1,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->get("/api/documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_normal_user_only_lists_visible_related_documents(): void
    {
        $user = $this->userWithRole('user');
        $otherUser = $this->userWithRole('user');

        $visibleDocument = $this->createStoredDocument($user);
        $this->createStoredDocument($otherUser);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleDocument->id);
    }

    public function test_user_never_sees_route_sheets_even_with_a_reservation_on_them(): void
    {
        // Une feuille de route couvre tous les trajets d'un véhicule sur une journée, toutes organisations
        // confondues : l'usager n'y a pas accès, même si une de ses réservations y figure.
        $user = $this->userWithRole('user');
        $reservation = $this->createReservationForUser($user);
        $routeSheet = $this->createRouteSheetDocument();
        $routeSheet->routeSheetReservations()->attach($reservation->id, ['position' => 1]);

        // Il garde les documents de ses réservations (ex. délibération).
        $deliberation = $this->createReservationDocument($reservation, DocumentType::DELIBERATION);

        Sanctum::actingAs($user);

        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deliberation->id);

        $this->getJson("/api/documents/{$routeSheet->id}")->assertForbidden();
        $this->get("/api/documents/{$routeSheet->id}/download")->assertForbidden();
        $this->getJson("/api/documents/{$deliberation->id}")->assertOk();
    }

    public function test_route_sheet_is_hidden_from_user_even_if_marked_as_theirs(): void
    {
        // Défense en profondeur : même avec user_id ou reservation_id renseigné, une feuille de route reste réservée au chauffeur.
        $user = $this->userWithRole('user');
        $reservation = $this->createReservationForUser($user);
        $routeSheet = $this->createRouteSheetDocument();
        $routeSheet->update(['user_id' => $user->id, 'reservation_id' => $reservation->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/documents/{$routeSheet->id}")->assertForbidden();
    }

    public function test_driver_only_sees_route_sheets_of_their_own_missions(): void
    {
        $owner = $this->userWithRole('user');
        $reservation = $this->createReservationForUser($owner);

        $driverUser = $this->driverUser();
        $otherDriverUser = $this->driverUser();
        $reservation->update(['driver_id' => $driverUser->driver->id]);

        $ownSheet = $this->createRouteSheetDocument();
        $ownSheet->routeSheetReservations()->attach($reservation->id, ['position' => 1]);

        // Feuille d'une mission d'un autre chauffeur, même organisation.
        $otherReservation = $this->createReservationForUser($owner);
        $otherReservation->update(['driver_id' => $otherDriverUser->driver->id]);
        $otherSheet = $this->createRouteSheetDocument();
        $otherSheet->routeSheetReservations()->attach($otherReservation->id, ['position' => 1]);

        Sanctum::actingAs($driverUser);

        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownSheet->id);

        $this->getJson("/api/documents/{$ownSheet->id}")->assertOk();
        $this->getJson("/api/documents/{$otherSheet->id}")->assertForbidden();
    }

    public function test_driver_member_of_an_organization_does_not_see_its_route_sheets_without_mission(): void
    {
        $owner = $this->userWithRole('user');
        $reservation = $this->createReservationForUser($owner);

        $driverUser = $this->driverUser();
        $driverUser->organizations()->attach($reservation->organization_id, ['relationship_type' => 'membre', 'is_default' => true]);

        $routeSheet = $this->createRouteSheetDocument();
        $routeSheet->routeSheetReservations()->attach($reservation->id, ['position' => 1]);

        Sanctum::actingAs($driverUser);

        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/documents/{$routeSheet->id}")->assertForbidden();
    }

    public function test_agent_still_sees_every_route_sheet(): void
    {
        $owner = $this->userWithRole('user');
        $reservation = $this->createReservationForUser($owner);
        $routeSheet = $this->createRouteSheetDocument();
        $routeSheet->routeSheetReservations()->attach($reservation->id, ['position' => 1]);

        Sanctum::actingAs($this->userWithRole('agent_communal'));

        $this->getJson('/api/documents')->assertOk()->assertJsonPath('data.0.id', $routeSheet->id);
        $this->getJson("/api/documents/{$routeSheet->id}")->assertOk();
    }

    public function test_signed_download_link_can_be_used_only_once(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user);
        $token = DocumentAccessToken::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'token' => 'tok_once_123',
            'expires_at' => now()->addHour(),
        ]);

        $url = URL::temporarySignedRoute(
            'api.documents.signed-download',
            now()->addHour(),
            ['document' => $document->id, 'token' => $token->token]
        );

        $this->get($url)->assertOk();
        $this->get($url)->assertForbidden();
    }

    public function test_signed_download_link_is_refused_when_token_is_expired(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user);
        $token = DocumentAccessToken::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'token' => 'tok_expired_123',
            'expires_at' => now()->subMinute(),
        ]);

        $url = URL::temporarySignedRoute(
            'api.documents.signed-download',
            now()->addHour(),
            ['document' => $document->id, 'token' => $token->token]
        );

        $this->get($url)->assertForbidden();
    }

    public function test_signed_download_link_is_refused_when_token_document_mismatch(): void
    {
        $user = $this->userWithRole('user');
        $first = $this->createStoredDocument($user);
        $second = $this->createStoredDocument($user);
        $token = DocumentAccessToken::create([
            'document_id' => $first->id,
            'user_id' => $user->id,
            'token' => 'tok_mismatch_123',
            'expires_at' => now()->addHour(),
        ]);

        $url = URL::temporarySignedRoute(
            'api.documents.signed-download',
            now()->addHour(),
            ['document' => $second->id, 'token' => $token->token]
        );

        $this->get($url)->assertForbidden();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createStoredDocument(User $user, bool $isAvailable = true): Document
    {
        $content = 'PDF TEST CONTENT';
        $filePath = 'documents/tests/justificatif-test.pdf';

        Storage::disk('local')->put($filePath, $content);

        // Justificatif téléversé par l'usager : un document qui lui appartient en propre.
        return Document::create([
            'user_id' => $user->id,
            'document_type' => DocumentType::JUSTIFICATIF,
            'file_name' => 'justificatif-test.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($content),
            'checksum' => hash('sha256', $content),
            'generation_mode' => 'auto',
            'generation_context' => [
                'source' => 'test',
                'template' => 'route_sheets.default',
            ],
            'template_key' => 'route_sheets.default',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 1,
            'is_available' => $isAvailable,
        ]);
    }

    private function driverUser(): User
    {
        $user = $this->userWithRole('chauffeur');
        Driver::create([
            'user_id' => $user->id,
            'licence_number' => 'LIC-'.$user->id,
            'licence_expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        return $user->load('driver');
    }

    private function createReservationDocument(Reservation $reservation, DocumentType $type): Document
    {
        return Document::create([
            'reservation_id' => $reservation->id,
            'user_id' => null,
            'document_type' => $type,
            'file_name' => 'deliberation-test.pdf',
            'file_path' => 'documents/tests/deliberation-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'checksum' => hash('sha256', 'deliberation'),
            'generation_mode' => 'auto',
            'template_key' => 'deliberations.default',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 1,
            'is_available' => true,
        ]);
    }

    private function createRouteSheetDocument(): Document
    {
        return Document::create([
            'user_id' => null,
            'reservation_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'route-sheet-test-v001.pdf',
            'file_path' => 'documents/route-sheets/test/route-sheet-test-v001.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'checksum' => hash('sha256', 'route-sheet'),
            'generation_mode' => 'auto',
            'generation_context' => [
                'source' => 'route_sheet_test',
            ],
            'template_key' => 'route_sheets.weekly',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => false,
            'version' => 1,
            'is_available' => true,
        ]);
    }

    private function createReservationForUser(User $user): Reservation
    {
        $pickupLocation = $this->createLocation('Place Albert 1er, 5500 Dinant');
        $destinationLocation = $this->createLocation('Rue de Namur 20, 5000 Namur');

        $organization = Organization::create([
            'name' => 'ASBL Test',
            'type' => 'asbl',
            'official_email' => 'asbl@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Contact',
            'location_id' => $pickupLocation->id,
            'is_priority' => false,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        return Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie educative',
            'activity_type' => 'standard',
            'distance_km' => 12,
            'estimated_duration_min' => 30,
            'status' => 'confirme',
            'approval_type' => 'manuel',
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
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);
    }
}
