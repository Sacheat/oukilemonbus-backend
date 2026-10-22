<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouteservice.key' => 'testing-key',
            'services.openrouteservice.base_url' => 'https://api.heigit.org',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_cannot_access_location_routes(): void
    {
        $this->getJson('/api/locations')->assertUnauthorized();
        $this->postJson('/api/locations', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_locations(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');

        Sanctum::actingAs($user);

        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonFragment([
                'formatted_address' => 'Rue Grande 1, Dinant',
            ]);
    }

    public function test_authenticated_user_can_create_location_with_geocoding(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        Http::fake([
            'api.heigit.org/pelias/v1/*' => Http::response([
                'features' => [
                    [
                        'geometry' => [
                            'coordinates' => [4.9123, 50.2611],
                        ],
                        'properties' => [
                            'id' => 'ors-place-1',
                            'layer' => 'address',
                            'match_type' => 'exact',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/locations', [
            'formatted_address' => 'Rue Grande 1, Dinant',
            'city' => 'Dinant',
            'country' => 'Belgique',
        ])
            ->assertCreated()
            ->assertJsonPath('data.formatted_address', 'Rue Grande 1, Dinant')
            ->assertJsonPath('data.place_id', 'ors-place-1')
            ->assertJsonPath('data.provider', 'openrouteservice');

        $this->assertDatabaseHas('locations', [
            'formatted_address' => 'Rue Grande 1, Dinant',
            'place_id' => 'ors-place-1',
            'provider' => 'openrouteservice',
        ]);
    }

    public function test_create_location_returns_validation_error_when_geocoding_fails(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        Http::fake([
            'api.heigit.org/pelias/v1/*' => Http::response(['features' => []]),
        ]);

        $this->postJson('/api/locations', [
            'formatted_address' => 'Adresse inconnue',
            'city' => 'Dinant',
        ])->assertUnprocessable();
    }

    public function test_create_location_rejects_a_geocoding_that_falls_back_to_the_city(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        // Réponse réelle d'ORS pour « Rue Grande 1, Dinant » : numéro mal découpé, repli sur le centre de Dinant
        Http::fake([
            'api.heigit.org/pelias/v1/*' => Http::response(['features' => [[
                'geometry' => ['coordinates' => [4.920467, 50.249286]],
                'properties' => ['id' => 'whosonfirst:locality:1', 'layer' => 'locality', 'match_type' => 'fallback'],
            ]]]),
        ]);

        $this->postJson('/api/locations', [
            'formatted_address' => 'Rue Grande 1, Dinant',
            'city' => 'Dinant',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Adresse trop imprécise : Rue Grande 1, Dinant. Indiquez le numéro et le code postal, ou choisissez une suggestion.');

        $this->assertDatabaseMissing('locations', ['place_id' => 'whosonfirst:locality:1']);
    }

    public function test_create_location_rejects_a_geocoding_that_falls_back_to_the_street(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        Http::fake([
            'api.heigit.org/pelias/v1/*' => Http::response(['features' => [[
                'geometry' => ['coordinates' => [4.908842, 50.261541]],
                'properties' => ['id' => 'polyline:1', 'layer' => 'street', 'match_type' => 'fallback'],
            ]]]),
        ]);

        $this->postJson('/api/locations', [
            'formatted_address' => 'Avenue Cadoux 8, Dinant',
            'city' => 'Dinant',
        ])->assertUnprocessable();
    }

    public function test_update_location_keeps_the_old_coordinates_when_the_new_address_is_imprecise(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        Http::fake([
            'api.heigit.org/pelias/v1/*' => Http::response(['features' => [[
                'geometry' => ['coordinates' => [4.920467, 50.249286]],
                'properties' => ['id' => 'whosonfirst:locality:1', 'layer' => 'locality', 'match_type' => 'fallback'],
            ]]]),
        ]);

        $location = $this->seededLocation('seed-dinant-centre');

        $this->patchJson("/api/locations/{$location->id}", [
            'formatted_address' => 'Rue Grande 1, Dinant',
        ])->assertUnprocessable();

        $this->assertSame((string) $location->latitude, (string) $location->fresh()->latitude);
    }

    public function test_authenticated_user_can_show_location(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');

        Sanctum::actingAs($user);
        $location = $this->seededLocation('seed-dinant-centre');

        $this->getJson("/api/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $location->id);
    }

    public function test_authenticated_user_can_update_location_without_regeocoding(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        Http::fake();

        $location = $this->seededLocation('seed-dinant-centre');

        $this->patchJson("/api/locations/{$location->id}", [
            'city' => 'Anseremme',
        ])
            ->assertOk()
            ->assertJsonPath('data.city', 'Anseremme');

        Http::assertNothingSent();
    }

    public function test_authenticated_user_can_update_location_and_regeocode_address(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        Http::fake([
            'api.heigit.org/pelias/v1/*' => Http::response([
                'features' => [
                    [
                        'geometry' => [
                            'coordinates' => [4.9155, 50.2599],
                        ],
                        'properties' => [
                            'id' => 'ors-place-2',
                            'layer' => 'address',
                            'match_type' => 'exact',
                        ],
                    ],
                ],
            ]),
        ]);

        $location = $this->seededLocation('seed-dinant-centre');

        $this->patchJson("/api/locations/{$location->id}", [
            'formatted_address' => 'Avenue Winston Churchill 1, Dinant',
        ])
            ->assertOk()
            ->assertJsonPath('data.formatted_address', 'Avenue Winston Churchill 1, Dinant')
            ->assertJsonPath('data.place_id', 'ors-place-2');
    }

    public function test_authenticated_user_without_location_permission_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/locations')->assertForbidden();
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededLocation(string $placeId): Location
    {
        return Location::query()->where('place_id', $placeId)->firstOrFail();
    }
}
