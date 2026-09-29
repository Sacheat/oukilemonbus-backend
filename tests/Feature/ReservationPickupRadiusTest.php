<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestMunicipalitySeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Deux règles distinctes, paramétrables sur la commune active :
 *   - distance du trajet (départ → destination, ORS) ≤ max_trip_distance_km ;
 *   - lieu de départ à ≤ max_pickup_distance_km, à vol d'oiseau, du dépôt (ou du centre de la commune).
 *
 * Commune de test : Dinant, centre 50.2606 / 4.9122. Liège est à ~63 km à vol d’oiseau.
 */
class ReservationPickupRadiusTest extends TestCase
{
    use DatabaseTransactions;

    /** Distance de l'itinéraire renvoyée par le faux ORS, en mètres. */
    private int $routeMeters = 6000;

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
            TestMunicipalitySeeder::class,
        ]);

        Carbon::setTestNow('2026-04-20 09:00:00');

        config([
            'services.openrouteservice.key'      => 'test-key',
            'services.openrouteservice.base_url' => 'https://ors.test',
            'reservations.closed_dates'          => [],
            'reservations.max_distance_km'       => 50,
            'reservations.max_pickup_distance_km' => 50,
        ]);

        // Trajet court (6 km) par défaut : seule la règle du lieu de départ peut bloquer.
        Http::fake(['https://ors.test/*' => fn () => Http::response([
            'routes' => [['summary' => ['distance' => $this->routeMeters, 'duration' => 900]]],
        ])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pickup_far_from_municipality_centre_is_rejected(): void
    {
        [$liege, $herstal] = $this->liegeLocations();
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        $response = $this->postJson('/api/reservations/preview', $this->payload($liege, $herstal))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_location_id')
            ->assertJsonMissingValidationErrors('destination_location_id');

        $this->assertStringContainsString('du centre de Dinant', $response->json('errors.pickup_location_id.0'));
        $this->assertStringContainsString('50 km', $response->json('errors.pickup_location_id.0'));
    }

    public function test_pickup_rule_also_applies_to_check_trip_and_creation(): void
    {
        [$liege, $herstal] = $this->liegeLocations();
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        $payload = $this->payload($liege, $herstal);

        $this->postJson('/api/reservations/check-trip', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_location_id');

        $this->postJson('/api/reservations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_location_id');
    }

    public function test_pickup_near_municipality_centre_is_allowed(): void
    {
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        $this->postJson('/api/reservations/preview', $this->payload(...$this->dinantLocations()))
            ->assertOk();
    }

    public function test_depot_takes_precedence_over_municipality_centre(): void
    {
        [$liege, $herstal] = $this->liegeLocations();
        $this->activeMunicipality()->update(['depot_location_id' => $liege->id]);
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        // Dépôt à Liège : un départ de Liège est accepté…
        $this->postJson('/api/reservations/preview', $this->payload($liege, $herstal))->assertOk();

        // … et un départ de Dinant (~63 km du dépôt) est refusé, avec « du dépôt » dans le message.
        $response = $this->postJson('/api/reservations/preview', $this->payload(...$this->dinantLocations()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_location_id');

        $this->assertStringContainsString('du dépôt', $response->json('errors.pickup_location_id.0'));
    }

    public function test_pickup_limit_is_configurable_on_the_municipality(): void
    {
        [$liege, $herstal] = $this->liegeLocations();
        $this->activeMunicipality()->update(['max_pickup_distance_km' => 80]);
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        $this->postJson('/api/reservations/preview', $this->payload($liege, $herstal))->assertOk();
    }

    public function test_rule_is_skipped_without_reference_point(): void
    {
        [$liege, $herstal] = $this->liegeLocations();
        $this->activeMunicipality()->update(['latitude' => null, 'longitude' => null, 'depot_location_id' => null]);
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        $this->postJson('/api/reservations/preview', $this->payload($liege, $herstal))->assertOk();
    }

    public function test_trip_distance_limit_is_configurable_and_separate_from_pickup_limit(): void
    {
        // Départ à Dinant (règle du départ respectée) mais trajet de 12 km > limite de 10 km.
        $this->routeMeters = 12000;
        $this->activeMunicipality()->update(['max_trip_distance_km' => 10]);
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        $this->postJson('/api/reservations/preview', $this->payload(...$this->dinantLocations()))
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors('pickup_location_id')
            ->assertJsonPath(
                'errors.destination_location_id.0',
                'La distance entre le lieu de départ et la destination dépasse 10 km.'
            );
    }

    private function payload(Location $pickup, Location $destination): array
    {
        return [
            'organization_id'         => Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail()->id,
            'pickup_location_id'      => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date'               => '2026-05-11',
            'departure_time'          => '09:00',
            'return_time'             => '11:00',
            'nb_children'             => 10,
            'nb_adults'               => 1,
            'trip_purpose'            => 'Sortie scolaire',
        ];
    }

    /** @return array{Location, Location} */
    private function dinantLocations(): array
    {
        return [
            Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail(),
            Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail(),
        ];
    }

    /** @return array{Location, Location} */
    private function liegeLocations(): array
    {
        return [
            Location::create([
                'formatted_address' => 'Place Saint-Lambert, Liège',
                'city' => 'Liège', 'country' => 'Belgique',
                'latitude' => 50.645100, 'longitude' => 5.573400, 'is_verified' => true,
            ]),
            Location::create([
                'formatted_address' => 'Place Licour, Herstal',
                'city' => 'Herstal', 'country' => 'Belgique',
                'latitude' => 50.662400, 'longitude' => 5.622500, 'is_verified' => true,
            ]),
        ];
    }

    private function activeMunicipality(): Municipality
    {
        return Municipality::query()->where('is_active', true)->firstOrFail();
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
