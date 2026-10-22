<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\TripReport;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * « Ma journée », partie 2 : réglages de la commune (navigation, délai du premier départ), tracé réel d'un trajet
 * (GET /reservations/{id}/route, ORS simulé) et contrôle des étapes loin du lieu prévu (meta.step_checks) ; journée du
 * chauffeur en un appel (GET /driver-journey/day) et compte rendu joint à la liste (GET /reservations?include=trip_report).
 * Trajet de test : mardi 16 juin 2026, départ 09:00 (Bruxelles, UTC+2) ; lieu de départ 50.26, 4.91 ; destination 50.27, 4.92.
 */
class DriverJourneyApiTest extends TestCase
{
    use DatabaseTransactions;

    private const ORS_URL = 'https://ors.test/openrouteservice/v2/directions/driving-car/geojson';

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

        config([
            'services.openrouteservice.key' => 'test-key',
            'services.openrouteservice.base_url' => 'https://ors.test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_settings_use_the_defaults_then_the_values_chosen_by_an_admin(): void
    {
        $municipality = $this->activeMunicipality();
        $municipality->update(['navigation_engine' => null, 'driver_departure_window_min' => null]);

        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));
        $this->getJson('/api/driver-journey/settings')
            ->assertOk()
            ->assertJsonPath('data.navigation_engine.key', 'openstreetmap')
            ->assertJsonPath('data.navigation_engine.android', 'geo:{lat},{lng}?q={lat},{lng}({label})')
            ->assertJsonPath('data.departure_window_minutes', 60)
            ->assertJsonPath('data.step_distance_warning_meters', 500)
            ->assertJsonPath('data.navigation_engines', [['key' => 'openstreetmap', 'label' => 'OpenStreetMap'], ['key' => 'google', 'label' => 'Google Maps']])
            ->assertJsonPath('data.default_departure_window_minutes', 60);

        // Un agent ne touche pas à ces réglages (le reste de la commune reste modifiable) ; un admin oui, avec contrôle des valeurs
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->patchJson("/api/municipalities/{$municipality->id}", ['navigation_engine' => 'google', 'driver_departure_window_min' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['navigation_engine', 'driver_departure_window_min']);
        $this->assertNull($municipality->refresh()->navigation_engine);

        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));
        $this->patchJson("/api/municipalities/{$municipality->id}", ['navigation_engine' => 'waze', 'driver_departure_window_min' => 300])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['navigation_engine', 'driver_departure_window_min']);
        $this->patchJson("/api/municipalities/{$municipality->id}", ['navigation_engine' => 'google', 'driver_departure_window_min' => 30])
            ->assertOk()
            ->assertJsonPath('data.navigation_engine', 'google')
            ->assertJsonPath('data.effective_driver_departure_window_min', 30);

        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));
        $this->getJson('/api/driver-journey/settings')
            ->assertOk()
            ->assertJsonPath('data.navigation_engine.key', 'google')
            ->assertJsonPath('data.navigation_engine.android', null)
            ->assertJsonPath('data.departure_window_minutes', 30);
    }

    public function test_the_departure_window_of_the_municipality_blocks_or_allows_the_first_departure(): void
    {
        $this->activeMunicipality()->update(['driver_departure_window_min' => 30]);
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));

        Carbon::setTestNow('2026-06-16 06:20:00'); // 08:20 à Bruxelles : 40 min avant, hors du délai de 30 min
        $this->record($reservation, 'depart_aller')->assertUnprocessable()->assertJsonValidationErrors('type');

        Carbon::setTestNow('2026-06-16 06:31:00'); // 08:31 : dans le délai
        $this->record($reservation, 'depart_aller')->assertCreated();
    }

    public function test_route_is_computed_on_first_opening_then_kept_until_a_place_changes(): void
    {
        Http::fake([self::ORS_URL => Http::response($this->orsResponse(), 200)]);
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));

        $this->getJson("/api/reservations/{$reservation->id}/route")
            ->assertOk()
            ->assertJsonPath('data.distance_m', 1850)
            ->assertJsonPath('data.duration_s', 240)
            ->assertJsonPath('data.geometry.0', [50.26, 4.91]) // [lat, lng]
            ->assertJsonPath('data.steps.0.instruction', 'Prendre la direction du nord sur Rue Grande')
            ->assertJsonPath('data.steps.1.name', ''); // « - » d'ORS = pas de nom

        // Deuxième ouverture : rien n'est recalculé
        $this->getJson("/api/reservations/{$reservation->id}/route")->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('trip_routes', 1);

        Http::assertSent(fn ($request): bool => $request['coordinates'] === [[4.91, 50.26], [4.92, 50.27]]
            && $request['language'] === 'fr'
            && $request['instructions'] === true);

        // La destination change : nouveau calcul, même ligne
        $reservation->update(['destination_location_id' => Location::query()->where('city', 'Anseremme')->firstOrFail()->id]);
        $this->getJson("/api/reservations/{$reservation->id}/route")->assertOk();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('trip_routes', 1);
    }

    public function test_route_is_null_when_the_service_fails_and_is_limited_to_the_assigned_driver_and_agents(): void
    {
        Http::fake([self::ORS_URL => Http::response(['error' => 'quota'], 500)]);
        $reservation = $this->confirmedReservation();

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->getJson("/api/reservations/{$reservation->id}/route")->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('trip_routes', 0);

        Sanctum::actingAs(Driver::query()->where('licence_number', 'DRV-SEED-002')->with('user')->firstOrFail()->user);
        $this->getJson("/api/reservations/{$reservation->id}/route")->assertForbidden();

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $this->getJson("/api/reservations/{$reservation->id}/route")->assertForbidden();
    }

    public function test_a_failed_route_is_not_retried_straight_away(): void
    {
        Http::fake([self::ORS_URL => Http::sequence()->push(['error' => 'down'], 500)->push($this->orsResponse(), 200)]);
        $reservation = $this->confirmedReservation();
        Cache::flush();
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->getJson("/api/reservations/{$reservation->id}/route")->assertOk()->assertJsonPath('data', null);
        $this->getJson("/api/reservations/{$reservation->id}/route")->assertOk()->assertJsonPath('data', null);
        Http::assertSentCount(1);

        // Le service revient et le délai est passé : le tracé est calculé
        Carbon::setTestNow(now()->addMinutes(4));
        $this->getJson("/api/reservations/{$reservation->id}/route")->assertOk()->assertJsonPath('data.distance_m', 1850);
        Http::assertSentCount(2);
    }

    public function test_agents_see_how_far_each_step_was_from_the_expected_place(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));

        Carbon::setTestNow('2026-06-16 07:00:00');
        // Départ à ~160 m du lieu de départ : normal
        $this->record($reservation, 'depart_aller', ['state' => 'envoyee', 'latitude' => 50.2591, 'longitude' => 4.9118, 'accuracy_m' => 15])->assertCreated();
        // Arrivée enregistrée encore au lieu de départ, à ~1,3 km de la destination : signalée
        Carbon::setTestNow('2026-06-16 07:20:00');
        $this->record($reservation, 'arrivee_aller', ['state' => 'envoyee', 'latitude' => 50.26, 'longitude' => 4.91, 'accuracy_m' => 10])->assertCreated();

        // Le chauffeur ne reçoit pas le contrôle
        $this->getJson("/api/reservations/{$reservation->id}/trip-progress")->assertOk()->assertJsonMissingPath('meta.step_checks');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $response = $this->getJson("/api/reservations/{$reservation->id}/trip-progress")
            ->assertOk()
            ->assertJsonPath('meta.step_distance_warning_meters', 500)
            ->assertJsonPath('meta.step_checks.0.type', 'depart_aller')
            ->assertJsonPath('meta.step_checks.0.expected_place', 'pickup')
            ->assertJsonPath('meta.step_checks.0.far_from_expected', false)
            ->assertJsonPath('meta.step_checks.1.type', 'arrivee_aller')
            ->assertJsonPath('meta.step_checks.1.expected_place', 'destination')
            ->assertJsonPath('meta.step_checks.1.expected_latitude', 50.27)
            ->assertJsonPath('meta.step_checks.1.far_from_expected', true);

        $this->assertEqualsWithDelta(160, $response->json('meta.step_checks.0.distance_m'), 30);
        $this->assertEqualsWithDelta(1330, $response->json('meta.step_checks.1.distance_m'), 50);
    }

    public function test_an_imprecise_position_is_not_reported_far_from_the_expected_place(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));
        Carbon::setTestNow('2026-06-16 07:00:00');
        // ~1,3 km de la destination, mais à 1 000 m près : 1 330 < 500 + 1 000, pas de signal
        $this->record($reservation, 'depart_aller', ['state' => 'envoyee', 'latitude' => 50.2591, 'longitude' => 4.9118, 'accuracy_m' => 15])->assertCreated();
        Carbon::setTestNow('2026-06-16 07:20:00');
        $this->record($reservation, 'arrivee_aller', ['state' => 'envoyee', 'latitude' => 50.26, 'longitude' => 4.91, 'accuracy_m' => 1000])->assertCreated();

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->getJson("/api/reservations/{$reservation->id}/trip-progress")
            ->assertOk()
            ->assertJsonPath('meta.step_checks.1.type', 'arrivee_aller')
            ->assertJsonPath('meta.step_checks.1.far_from_expected', false);
    }

    public function test_the_day_of_the_driver_comes_in_one_call_with_progress_neighbour_days_and_settings(): void
    {
        // Jours lointains, pour ne pas croiser d'autres trajets de la base de test
        $before = $this->confirmedReservation(['trip_date' => '2031-03-10']);
        $late = $this->confirmedReservation(['trip_date' => '2031-03-12', 'departure_time' => '14:00:00', 'return_time' => '16:00:00']);
        $early = $this->confirmedReservation(['trip_date' => '2031-03-12', 'status' => ReservationStatus::APPROUVE]);
        $cancelled = $this->confirmedReservation(['trip_date' => '2031-03-12', 'status' => ReservationStatus::ANNULE]);
        $cancelledLater = $this->confirmedReservation(['trip_date' => '2031-03-13', 'status' => ReservationStatus::ANNULE]);
        $otherDriver = $this->confirmedReservation([
            'trip_date' => '2031-03-12',
            'driver_id' => Driver::query()->where('licence_number', 'DRV-SEED-002')->firstOrFail()->id,
        ]);
        $after = $this->confirmedReservation(['trip_date' => '2031-03-14']);

        Carbon::setTestNow('2031-03-12 13:05:00'); // 14:05 à Bruxelles (UTC+1 en mars) : le trajet de 14:00 peut partir
        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));
        $this->record($late, 'depart_aller')->assertCreated();

        $response = $this->getJson('/api/driver-journey/day?date=2031-03-12')
            ->assertOk()
            ->assertJsonPath('data.date', '2031-03-12')
            ->assertJsonPath('data.previous_day', '2031-03-10')
            ->assertJsonPath('data.next_day', '2031-03-14')
            ->assertJsonPath('data.settings.navigation_engine.key', 'openstreetmap')
            ->assertJsonCount(2, 'data.missions')
            ->assertJsonPath('data.missions.0.id', $early->id)
            ->assertJsonPath('data.missions.1.id', $late->id)
            ->assertJsonPath('data.missions.1.organization.id', $late->organization_id)
            ->assertJsonPath('data.missions.1.trip_started_local_time', '14:05')
            ->assertJsonCount(2, 'data.progress')
            ->assertJsonPath('data.progress.0.reservation_id', $early->id)
            ->assertJsonPath('data.progress.0.state', 'a_venir')
            ->assertJsonPath('data.progress.1.reservation_id', $late->id)
            ->assertJsonPath('data.progress.1.state', 'en_route_aller');

        $ids = collect($response->json('data.missions'))->pluck('id');
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertNotContains($otherDriver->id, $ids);
        $this->assertNotNull($before->id);
        $this->assertNotNull($cancelledLater->id);
        $this->assertNotNull($after->id);
    }

    public function test_the_day_needs_a_valid_date_and_is_reserved_to_drivers(): void
    {
        Sanctum::actingAs($this->user('chauffeur.test@bus-communal.local'));
        $this->getJson('/api/driver-journey/day')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/driver-journey/day?date=12/03/2031')->assertUnprocessable()->assertJsonValidationErrors('date');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->getJson('/api/driver-journey/day?date=2031-03-12')->assertForbidden();

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $this->getJson('/api/driver-journey/day?date=2031-03-12')->assertForbidden();
    }

    public function test_the_reservation_list_can_carry_the_trip_report_for_those_who_may_read_it(): void
    {
        $reported = $this->confirmedReservation(['trip_date' => '2031-03-12']);
        $waiting = $this->confirmedReservation(['trip_date' => '2031-03-12', 'departure_time' => '14:00:00', 'return_time' => '16:00:00']);
        $chauffeur = $this->user('chauffeur.test@bus-communal.local');
        TripReport::create([
            'reservation_id' => $reported->id,
            'driver_id' => $reported->driver_id,
            'outcome' => 'realise',
            'actual_departure_time' => '09:05',
            'actual_return_time' => '11:00',
            'actual_nb_children' => 10,
            'actual_nb_adults' => 1,
            'reported_by_user_id' => $chauffeur->id,
            'reported_at' => now(),
        ]);
        $query = '/api/reservations?date_from=2031-03-12&date_to=2031-03-12&sort=asc';

        foreach (['chauffeur.test@bus-communal.local', 'agent.test@bus-communal.local'] as $email) {
            Sanctum::actingAs($this->user($email));
            $this->getJson("{$query}&include=trip_report")
                ->assertOk()
                ->assertJsonPath('data.0.id', $reported->id)
                ->assertJsonPath('data.0.trip_report.outcome', 'realise')
                ->assertJsonPath('data.0.trip_report.reported_by_driver', true)
                ->assertJsonPath('data.1.id', $waiting->id)
                ->assertJsonPath('data.1.trip_report', null);
        }

        // Sans include : pas de clé (comme avant)
        Sanctum::actingAs($chauffeur);
        $this->assertArrayNotHasKey('trip_report', $this->getJson($query)->assertOk()->json('data.0'));
        $this->getJson("{$query}&include=autre")->assertUnprocessable()->assertJsonValidationErrors('include');
    }

    private function record(Reservation $reservation, string $type, array $position = ['state' => 'refusee'])
    {
        return $this->postJson("/api/reservations/{$reservation->id}/trip-events", ['type' => $type, 'position' => $position]);
    }

    private function orsResponse(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => [[4.91, 50.26], [4.915, 50.265], [4.92, 50.27]]],
                'properties' => [
                    'summary' => ['distance' => 1850.4, 'duration' => 240.2],
                    'segments' => [[
                        'distance' => 1850.4,
                        'duration' => 240.2,
                        'steps' => [
                            ['distance' => 1200.0, 'duration' => 150.0, 'instruction' => 'Prendre la direction du nord sur Rue Grande', 'name' => 'Rue Grande'],
                            ['distance' => 650.4, 'duration' => 90.2, 'instruction' => 'Tourner à droite', 'name' => '-'],
                            ['distance' => 0.0, 'duration' => 0.0, 'instruction' => 'Arrivée à destination', 'name' => '-'],
                        ],
                    ]],
                ],
            ]],
        ];
    }

    private function activeMunicipality(): Municipality
    {
        return Municipality::query()->where('is_active', true)->first()
            ?? Municipality::create(['name' => 'Dinant', 'country' => 'BE', 'source' => 'manual', 'is_active' => true]);
    }

    private function confirmedReservation(array $overrides = []): Reservation
    {
        return Reservation::create([...$this->reservationAttributes(), ...$overrides]);
    }

    private function reservationAttributes(): array
    {
        return [
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail()->id,
            'vehicle_id' => Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail()->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::AUTO,
        ];
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
