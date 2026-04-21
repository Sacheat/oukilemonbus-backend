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
            'services.openrouteservice.base_url' => 'https://api.openrouteservice.org',
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
            'api.openrouteservice.org/*' => Http::response([
                'features' => [
                    [
                        'geometry' => [
                            'coordinates' => [4.9123, 50.2611],
                        ],
                        'properties' => [
                            'id' => 'ors-place-1',
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
            'api.openrouteservice.org/*' => Http::response(['features' => []]),
        ]);

        $this->postJson('/api/locations', [
            'formatted_address' => 'Adresse inconnue',
            'city' => 'Dinant',
        ])->assertUnprocessable();
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
            'api.openrouteservice.org/*' => Http::response([
                'features' => [
                    [
                        'geometry' => [
                            'coordinates' => [4.9155, 50.2599],
                        ],
                        'properties' => [
                            'id' => 'ors-place-2',
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
