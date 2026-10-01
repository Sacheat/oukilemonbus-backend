<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\ContextBuilders\RouteSheetContextBuilder;
use App\Services\Documents\RouteSheetDocumentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feuilles de route de l'espace chauffeur : GET /route-sheets/driver (PDF de la semaine) et GET /route-sheets
 * (feuilles officielles, dernière version). Semaine de test : lundi 15 → dimanche 21 juin 2026.
 */
class DriverRouteSheetApiTest extends TestCase
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
    }

    public function test_driver_week_contains_only_own_confirmed_missions_with_weekdays_and_busy_weekend_days(): void
    {
        $this->reservation(['trip_date' => '2026-06-16']);
        $this->reservation(['trip_date' => '2026-06-20']); // samedi : affiché car il a une mission
        $this->reservation(['trip_date' => '2026-06-17', 'status' => ReservationStatus::ANNULE]);
        $this->reservation(['trip_date' => '2026-06-18', 'driver_id' => $this->otherDriver()->id]);
        $this->reservation(['trip_date' => '2026-06-23']); // semaine suivante

        $context = app(RouteSheetContextBuilder::class)->buildForDriverWeek($this->driver(), '2026-06-18');

        $this->assertSame('2026-06-15', $context['period']['start_date']);
        $this->assertSame(
            ['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18', '2026-06-19', '2026-06-20'],
            array_column($context['days'], 'date')
        );
        $this->assertSame(2, $context['totals']['missions']);
        $this->assertCount(1, $context['days'][1]['rows']);
        $this->assertCount(0, $context['days'][2]['rows']); // annulée : absente
        $this->assertCount(0, $context['days'][3]['rows']); // autre chauffeur : absente
        $this->assertSame('1-VJX-649', $context['days'][1]['rows'][0]['vehicle']['licence_plate']);
    }

    public function test_driver_week_pdf_access_rules(): void
    {
        $this->reservation(['trip_date' => '2026-06-16']);

        Sanctum::actingAs($this->driver()->user);
        $this->get('/api/route-sheets/driver?week=2026-06-16')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/route-sheets/driver?week=2026-06-16&driver_id='.$this->otherDriver()->id)->assertForbidden();
        $this->getJson('/api/route-sheets/driver')->assertUnprocessable()->assertJsonValidationErrors('week');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->getJson('/api/route-sheets/driver?week=2026-06-16')->assertUnprocessable()->assertJsonValidationErrors('driver_id');
        $this->get('/api/route-sheets/driver?week=2026-06-16&driver_id='.$this->driver()->id)->assertOk();

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $this->getJson('/api/route-sheets/driver?week=2026-06-16&driver_id='.$this->driver()->id)->assertForbidden();
    }

    public function test_official_sheets_list_only_the_latest_version_visible_to_the_driver(): void
    {
        $vehicleId = $this->vehicle()->id;
        $ownTrip = $this->reservation(['trip_date' => '2026-06-16']);
        $this->reservation(['trip_date' => '2026-06-16', 'driver_id' => $this->otherDriver()->id, 'departure_time' => '13:00:00', 'return_time' => '15:00:00']);
        $this->reservation(['trip_date' => '2026-06-17', 'driver_id' => $this->otherDriver()->id]);

        $service = app(RouteSheetDocumentService::class);
        $firstVersion = $service->generateForServiceDateVehicle('2026-06-16', $vehicleId);
        $latestVersion = $service->generateForServiceDateVehicle('2026-06-16', $vehicleId);
        $otherDriverSheet = $service->generateForServiceDateVehicle('2026-06-17', $vehicleId);

        Sanctum::actingAs($this->driver()->user);
        $response = $this->getJson('/api/route-sheets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $latestVersion->id)
            ->assertJsonPath('data.0.service_date', '2026-06-16')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.vehicle.licence_plate', '1-VJX-649')
            ->assertJsonPath('data.0.trip_count', 2)
            ->assertJsonPath('data.0.own_trip_count', 1);
        $this->assertNotContains($firstVersion->id, collect($response->json('data'))->pluck('id'));

        // Agent : toutes les feuilles (dernière version), filtrables par date
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->getJson('/api/route-sheets')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/route-sheets?date_from=2026-06-17')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $otherDriverSheet->id)
            ->assertJsonPath('data.0.own_trip_count', null);

        $this->assertSame($ownTrip->id, $latestVersion->routeSheetReservations()->orderBy('reservations.id')->first()->id);
    }

    public function test_official_sheets_skip_unavailable_versions_for_internal_users(): void
    {
        $vehicleId = $this->vehicle()->id;
        $this->reservation(['trip_date' => '2026-06-16']);

        $service = app(RouteSheetDocumentService::class);
        $firstVersion = $service->generateForServiceDateVehicle('2026-06-16', $vehicleId);
        $latestVersion = $service->generateForServiceDateVehicle('2026-06-16', $vehicleId);
        $latestVersion->update(['is_available' => false]);

        // Dernière version indisponible : seule la version précédente est listée
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->getJson('/api/route-sheets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $firstVersion->id);
    }

    private function reservation(array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => $this->driver()->id,
            'vehicle_id' => $this->vehicle()->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::AUTO,
            ...$attributes,
        ]);
    }

    private function driver(): Driver
    {
        return Driver::query()->where('licence_number', 'DRV-SEED-001')->with('user')->firstOrFail();
    }

    private function otherDriver(): Driver
    {
        return Driver::query()->where('licence_number', 'DRV-SEED-002')->firstOrFail();
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
