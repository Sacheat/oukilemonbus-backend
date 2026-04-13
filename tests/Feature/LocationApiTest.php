<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouteservice.key' => 'testing-key',
            'services.openrouteservice.base_url' => 'https://api.openrouteservice.org',
        ]);
    }

    public function test_guest_cannot_access_location_routes(): void
    {
        $this->getJson('/api/locations')->assertUnauthorized();
        $this->postJson('/api/locations', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_locations(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Location::create([
            'formatted_address' => 'Rue Grande 1, Dinant',
            'city' => 'Dinant',
            'country' => 'Belgique',
        ]);

        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonPath('data.0.formatted_address', 'Rue Grande 1, Dinant');
    }

    public function test_authenticated_user_can_create_location_with_geocoding(): void
    {
        Sanctum::actingAs(User::factory()->create());

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
        Sanctum::actingAs(User::factory()->create());

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
        Sanctum::actingAs(User::factory()->create());

        $location = Location::create([
            'formatted_address' => 'Rue Grande 1, Dinant',
            'city' => 'Dinant',
            'country' => 'Belgique',
        ]);

        $this->getJson("/api/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $location->id);
    }

    public function test_authenticated_user_can_update_location_without_regeocoding(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Http::fake();

        $location = Location::create([
            'formatted_address' => 'Rue Grande 1, Dinant',
            'city' => 'Dinant',
            'country' => 'Belgique',
        ]);

        $this->patchJson("/api/locations/{$location->id}", [
            'city' => 'Anseremme',
        ])
            ->assertOk()
            ->assertJsonPath('data.city', 'Anseremme');

        Http::assertNothingSent();
    }

    public function test_authenticated_user_can_update_location_and_regeocode_address(): void
    {
        Sanctum::actingAs(User::factory()->create());

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

        $location = Location::create([
            'formatted_address' => 'Rue Grande 1, Dinant',
            'city' => 'Dinant',
            'country' => 'Belgique',
        ]);

        $this->patchJson("/api/locations/{$location->id}", [
            'formatted_address' => 'Avenue Winston Churchill 1, Dinant',
        ])
            ->assertOk()
            ->assertJsonPath('data.formatted_address', 'Avenue Winston Churchill 1, Dinant')
            ->assertJsonPath('data.place_id', 'ors-place-2');
    }
}
