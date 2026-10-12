<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Liste des passagers (carnet de bord) : jointe par le demandeur jusqu'au départ, par un agent à tout moment ;
 * lue par l'agent, le demandeur et le chauffeur affecté ; une seule par réservation ; supprimée 3 ans après l'année du trajet.
 */
class PassengerListApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
            TestOrganizationsSeeder::class,
            TestFleetSeeder::class,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-12 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_requester_joins_then_replaces_the_list_and_only_the_latest_file_is_kept(): void
    {
        $reservation = $this->reservation('2026-11-05');
        Sanctum::actingAs($this->user('user.test@bus-communal.local'));

        $first = $this->postJson("/api/reservations/{$reservation->id}/passenger-list", ['passenger_list' => $this->pdf()])
            ->assertCreated()
            ->assertJsonPath('data.document_type', 'liste_passagers')
            ->assertJsonPath('data.version', 1)
            ->json('data.id');
        $firstPath = Document::findOrFail($first)->file_path;
        Storage::disk('local')->assertExists($firstPath);

        $second = $this->postJson("/api/reservations/{$reservation->id}/passenger-list", ['passenger_list' => $this->csv()])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->json('data.id');

        // L'ancienne liste disparaît, fichier compris : une liste nominative ne traîne pas
        $this->assertDatabaseMissing('documents', ['id' => $first]);
        Storage::disk('local')->assertMissing($firstPath);
        $this->getJson("/api/reservations/{$reservation->id}/passenger-list")
            ->assertOk()
            ->assertJsonPath('data.id', $second)
            ->assertJsonPath('meta.can_replace', true);
    }

    public function test_the_assigned_driver_reads_and_downloads_it_but_cannot_change_it_and_strangers_see_nothing(): void
    {
        $reservation = $this->reservation('2026-11-05');
        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $documentId = $this->postJson("/api/reservations/{$reservation->id}/passenger-list", ['passenger_list' => $this->pdf()])->json('data.id');

        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));
        $this->getJson("/api/reservations/{$reservation->id}/passenger-list")
            ->assertOk()
            ->assertJsonPath('data.id', $documentId)
            ->assertJsonPath('meta.can_replace', false);
        $this->get("/api/documents/{$documentId}/download")->assertOk();
        $this->postJson("/api/reservations/{$reservation->id}/passenger-list", ['passenger_list' => $this->pdf()])->assertForbidden();

        Sanctum::actingAs($this->user('responsable.test@bus-communal.local'));
        $this->getJson("/api/reservations/{$reservation->id}/passenger-list")->assertForbidden();
        $this->get("/api/documents/{$documentId}/download")->assertForbidden();
    }

    public function test_after_departure_the_requester_can_no_longer_change_it_but_an_agent_still_can(): void
    {
        $reservation = $this->reservation('2026-10-12', '08:00:00');
        Sanctum::actingAs($this->user('user.test@bus-communal.local'));

        $this->getJson("/api/reservations/{$reservation->id}/passenger-list")
            ->assertOk()
            ->assertJsonPath('meta.can_replace', false);
        $this->postJson("/api/reservations/{$reservation->id}/passenger-list", ['passenger_list' => $this->pdf()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passenger_list');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson("/api/reservations/{$reservation->id}/passenger-list", ['passenger_list' => $this->pdf()])->assertCreated();
        $this->deleteJson("/api/reservations/{$reservation->id}/passenger-list")->assertNoContent();
        $this->assertSame(0, Document::query()->where('reservation_id', $reservation->id)->count());
    }

    public function test_dangerous_or_unknown_files_and_cancelled_reservations_are_refused(): void
    {
        $reservation = $this->reservation('2026-11-05');
        Sanctum::actingAs($this->user('user.test@bus-communal.local'));

        $this->postJson("/api/reservations/{$reservation->id}/passenger-list", [
            'passenger_list' => UploadedFile::fake()->createWithContent('liste.csv', "Nom;Prénom\n<?php system('id'); ?>"),
        ])->assertUnprocessable()->assertJsonValidationErrors('passenger_list');
        $this->postJson("/api/reservations/{$reservation->id}/passenger-list", [
            'passenger_list' => UploadedFile::fake()->createWithContent('liste.docx', 'texte'),
        ])->assertUnprocessable()->assertJsonValidationErrors('passenger_list');
        $this->postJson("/api/reservations/{$reservation->id}/passenger-list", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.passenger_list.0', 'Choisissez le fichier de la liste des passagers.');

        $reservation->update(['status' => ReservationStatus::ANNULE]);
        $this->postJson("/api/reservations/{$reservation->id}/passenger-list", ['passenger_list' => $this->pdf()])->assertForbidden();
        $this->assertSame(0, Document::query()->where('reservation_id', $reservation->id)->count());
    }

    public function test_lists_are_deleted_three_years_after_the_year_of_the_trip(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);
        $old = $this->reservation('2026-03-10');
        $kept = $this->reservation('2027-03-10');
        $oldDocument = $this->postJson("/api/reservations/{$old->id}/passenger-list", ['passenger_list' => $this->pdf()])->json('data.id');
        $keptDocument = $this->postJson("/api/reservations/{$kept->id}/passenger-list", ['passenger_list' => $this->pdf()])->json('data.id');
        $oldPath = Document::findOrFail($oldDocument)->file_path;

        // 31/12/2029 : le trajet de 2026 est encore dans ses trois années
        Carbon::setTestNow('2029-12-31 22:00:00');
        $this->artisan('documents:purge-passenger-lists')->assertSuccessful();
        $this->assertDatabaseHas('documents', ['id' => $oldDocument]);

        // 01/01/2030 : il est supprimé (fichier compris), celui de 2027 reste jusqu'au 31/12/2030
        Carbon::setTestNow('2030-01-01 08:00:00');
        $this->artisan('documents:purge-passenger-lists')->expectsOutput('1 liste(s) des passagers supprimée(s).')->assertSuccessful();
        $this->assertDatabaseMissing('documents', ['id' => $oldDocument]);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertDatabaseHas('documents', ['id' => $keptDocument]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('liste.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF");
    }

    private function csv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('liste.csv', "Nom;Prénom;Classe\nDupont;Léa;P3\nMartin;Hugo;P3\n");
    }

    private function reservation(string $tripDate, string $departure = '09:00:00'): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => Driver::query()->whereHas('user', fn ($query) => $query->where('email', 'chauffeur.test@bus-communal.local'))->value('id')
                ?? Driver::query()->orderBy('id')->value('id'),
            'trip_date' => $tripDate,
            'departure_time' => $departure,
            'return_time' => '11:00:00',
            'nb_children' => 20,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::AUTO,
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
