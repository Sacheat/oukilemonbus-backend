<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Models\Location;
use App\Models\Municipality;
use App\Models\MunicipalityLocality;
use App\Models\User;
use App\Services\Locations\MunicipalityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestMunicipalitySeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MunicipalitySettingsApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.geonames.base_url' => 'https://geonames.test',
            'services.geonames.username' => 'demo',
            'services.geonames.max_rows' => 100,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
            TestMunicipalitySeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_cannot_access_municipality_settings_routes(): void
    {
        $this->getJson('/api/municipalities')->assertUnauthorized();
        $this->postJson('/api/municipalities', [])->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_manage_municipalities(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/municipalities')->assertForbidden();
        $this->postJson('/api/municipalities', [
            'name' => 'Dinant',
        ])->assertForbidden();
    }

    public function test_admin_can_create_active_municipality_with_localities(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $this->postJson('/api/municipalities', [
            'name' => 'Dinant',
            'country' => 'be',
            'postal_code' => '5500',
            'is_active' => true,
            'localities' => ['Dinant', 'Anseremme', 'Dréhance'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dinant')
            ->assertJsonPath('data.external_id', null)
            ->assertJsonPath('data.nis_code', null)
            ->assertJsonPath('data.country', 'BE')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonCount(3, 'data.localities');

        $this->assertDatabaseHas('municipality_localities', [
            'name' => 'Dréhance',
            'normalized_name' => 'drehance',
        ]);
    }

    public function test_only_one_municipality_is_active_at_a_time(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $dinant = $this->activeMunicipality();

        $this->postJson('/api/municipalities', [
            'name' => 'Namur',
            'country' => 'BE',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse($dinant->fresh()->is_active);
        $this->assertTrue(Municipality::where('name', 'Namur')->firstOrFail()->is_active);
    }

    public function test_admin_sets_and_clears_the_on_call_contact(): void
    {
        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));
        $dinant = $this->activeMunicipality();

        $this->patchJson("/api/municipalities/{$dinant->id}", ['on_call_email' => 'pas-un-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('on_call_email');

        $this->patchJson("/api/municipalities/{$dinant->id}", [
            'on_call_name' => 'Garde technique',
            'on_call_email' => 'garde@dinant.test',
            'on_call_phone' => '082 40 40 40',
        ])->assertOk()
            ->assertJsonPath('data.on_call_email', 'garde@dinant.test')
            ->assertJsonPath('data.on_call_phone', '082 40 40 40');

        $this->patchJson("/api/municipalities/{$dinant->id}", ['on_call_email' => null])
            ->assertOk()
            ->assertJsonPath('data.on_call_email', null)
            ->assertJsonPath('data.on_call_name', 'Garde technique');
    }

    public function test_admin_can_add_update_and_deactivate_locality(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $municipality = $this->activeMunicipality();

        $response = $this->postJson("/api/municipalities/{$municipality->id}/localities", [
            'name' => 'Foy Notre Dame',
        ])->assertCreated()
            ->assertJsonPath('data.normalized_name', 'foy notre dame');

        $localityId = $response->json('data.id');

        $this->patchJson("/api/municipality-localities/{$localityId}", [
            'name' => 'Foy-Notre-Dame',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Foy-Notre-Dame')
            ->assertJsonPath('data.normalized_name', 'foy-notre-dame');

        $this->patchJson("/api/municipality-localities/{$localityId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_municipality_service_uses_active_database_settings_before_config_fallback(): void
    {
        config([
            'municipality.name' => 'Dinant',
            'municipality.localities' => ['Dinant'],
        ]);

        $this->activeMunicipality()->update(['is_active' => false]);

        $municipality = Municipality::create([
            'name' => 'Yvoir',
            'country' => 'BE',
            'is_active' => true,
        ]);

        MunicipalityLocality::create([
            'municipality_id' => $municipality->id,
            'name' => 'Godinne',
            'normalized_name' => 'godinne',
            'is_active' => true,
        ]);

        $location = Location::create([
            'formatted_address' => 'Rue du Centre 1, Godinne',
            'city' => 'Godinne',
            'country' => 'Belgique',
        ]);

        $service = app(MunicipalityService::class);

        $this->assertSame('Yvoir', $service->name());
        $this->assertTrue($service->containsLocation($location));
    }

    public function test_org_registration_scoring_uses_active_database_municipality(): void
    {
        $this->activeMunicipality()->update(['is_active' => false]);

        $municipality = Municipality::create([
            'name' => 'Yvoir',
            'country' => 'BE',
            'is_active' => true,
        ]);

        MunicipalityLocality::create([
            'municipality_id' => $municipality->id,
            'name' => 'Godinne',
            'normalized_name' => 'godinne',
            'is_active' => true,
        ]);

        $location = Location::create([
            'formatted_address' => 'Rue du Centre 1, Godinne',
            'city' => 'Godinne',
            'country' => 'Belgique',
            'is_verified' => true,
        ]);

        $user = $this->seededUser('user.test@bus-communal.local');
        Sanctum::actingAs($user);

        $this->postJson('/api/org-registration-requests', [
            'organization_name' => 'ASBL Godinne Solidaire',
            'organization_type' => OrganizationType::ASBL->value,
            'enterprise_number' => '0123.456.749',
            'official_email' => 'contact@godinne-solidaire.test',
            'phone' => '082000000',
            'location_id' => $location->id,
            'contact_person_name' => 'Alice Martin',
            'contact_person_role' => 'Responsable',
        ])
            ->assertCreated()
            ->assertJsonPath('data.confidence_note', 75)
            ->assertJsonFragment([
                'Adresse situee dans la commune de Yvoir.',
            ]);
    }

    public function test_admin_can_sync_localities_from_geonames_commune_level(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $municipality = $this->activeMunicipality(); // Dinant

        MunicipalityLocality::create([
            'municipality_id' => $municipality->id,
            'name'            => 'Noise From Previous Sync',
            'normalized_name' => 'noise from previous sync',
            'source'          => 'geonames',
            'is_active'       => true,
        ]);

        Http::fake([
            // findMunicipality → Dinant est une commune (ADM4)
            'https://geonames.test/searchJSON*' => Http::response([
                'geonames' => [[
                    'geonameId'   => 2799366,
                    'name'        => 'Dinant',
                    'fcode'       => 'ADM4',
                    'countryCode' => 'BE',
                    'adminCode1'  => 'WAL',
                    'adminCode2'  => '93',
                    'adminCode3'  => '91',
                    'adminCode4'  => '91034',
                    'lat'         => '50.26064',
                    'lng'         => '4.91247',
                ]],
            ]),
            // fetchSubLocalities → sections de Dinant via childrenJSON
            'https://geonames.test/childrenJSON*' => Http::response([
                'geonames' => [
                    ['geonameId' => 2803130, 'name' => 'Anseremme'],
                    ['geonameId' => 2801402, 'name' => 'Bouvignes-sur-Meuse'],
                    ['geonameId' => 2803130, 'name' => 'Anseremme'], // doublon GeoNames → upsert idempotent
                ],
            ]),
        ]);

        $this->postJson("/api/municipalities/{$municipality->id}/sync-localities")
            ->assertOk()
            ->assertJsonPath('data.external_id', '2799366')
            ->assertJsonPath('data.nis_code', '91034')
            ->assertJsonPath('data.source', 'geonames')
            // Le centre GeoNames devient le point de référence de repli.
            ->assertJsonPath('data.latitude', '50.26064000')
            ->assertJsonPath('data.longitude', '4.91247000');

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Anseremme',
            'normalized_name' => 'anseremme',
            'external_id'     => '2803130',
            'source'          => 'geonames',
            'is_active'       => true,
        ]);

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Bouvignes-sur-Meuse',
            'normalized_name' => 'bouvignes-sur-meuse',
            'external_id'     => '2801402',
            'source'          => 'geonames',
            'is_active'       => true,
        ]);

        // La localité absente du dernier sync est désactivée
        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'normalized_name' => 'noise from previous sync',
            'source'          => 'geonames',
            'is_active'       => false,
        ]);
    }

    public function test_admin_can_sync_localities_from_geonames_province_level(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $municipality = Municipality::create([
            'name'      => 'Province de Namur',
            'country'   => 'BE',
            'is_active' => true,
        ]);

        // findMunicipality → Province (ADM2) puis searchCommunes → communes ADM4
        Http::fake([
            'https://geonames.test/searchJSON*' => Http::sequence()
                ->push(['geonames' => [[   // findMunicipality
                    'geonameId'  => 2790142,
                    'name'       => 'Province de Namur',
                    'fcode'      => 'ADM2',
                    'adminCode1' => 'WAL',
                    'adminCode2' => '93',
                ]]])
                ->push(['geonames' => [    // searchCommunes — page 1 (non pleine → arrêt)
                    ['geonameId' => 2799366, 'name' => 'Dinant'],
                    ['geonameId' => 2751283, 'name' => 'Namur'],
                    ['geonameId' => 2790049, 'name' => 'Philippeville'],
                ]]),
        ]);

        $this->postJson("/api/municipalities/{$municipality->id}/sync-localities")
            ->assertOk()
            ->assertJsonPath('data.external_id', '2790142')
            ->assertJsonPath('data.source', 'geonames');

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Dinant',
            'source'          => 'geonames',
            'is_active'       => true,
        ]);
        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Namur',
            'source'          => 'geonames',
            'is_active'       => true,
        ]);
        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Philippeville',
            'source'          => 'geonames',
            'is_active'       => true,
        ]);
    }

    public function test_admin_can_sync_localities_from_geonames_country_level(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $municipality = Municipality::create([
            'name'      => 'Belgique',
            'country'   => 'BE',
            'is_active' => true,
        ]);

        // findMunicipality → PCLI puis searchCommunes paginé (page 1 pleine → page 2 vide)
        Http::fake([
            'https://geonames.test/searchJSON*' => Http::sequence()
                ->push(['geonames' => [[    // findMunicipality
                    'geonameId'  => 2802361,
                    'name'       => 'Belgium',
                    'fcode'      => 'PCLI',
                    'countryCode' => 'BE',
                ]]])
                ->push(['geonames' => [     // searchCommunes — page 1 (2 < batchSize → arrêt)
                    ['geonameId' => 2799366, 'name' => 'Dinant'],
                    ['geonameId' => 2751283, 'name' => 'Namur'],
                ]]),
        ]);

        $this->postJson("/api/municipalities/{$municipality->id}/sync-localities")
            ->assertOk()
            ->assertJsonPath('data.external_id', '2802361')
            ->assertJsonPath('data.source', 'geonames');

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Dinant',
            'source'          => 'geonames',
        ]);
        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Namur',
            'source'          => 'geonames',
        ]);
    }

    public function test_commune_with_no_geonames_children_uses_entity_itself_as_locality(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $municipality = $this->activeMunicipality();

        Http::fake([
            'https://geonames.test/searchJSON*' => Http::response([
                'geonames' => [[
                    'geonameId'  => 2799366,
                    'name'       => 'Dinant',
                    'fcode'      => 'ADM4',
                    'countryCode' => 'BE',
                ]],
            ]),
            // childrenJSON vide → fallback sur l'entité elle-même
            'https://geonames.test/childrenJSON*' => Http::response(['geonames' => []]),
        ]);

        $this->postJson("/api/municipalities/{$municipality->id}/sync-localities")->assertOk();

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name'            => 'Dinant',
            'external_id'     => '2799366',
            'source'          => 'geonames',
        ]);
    }

    public function test_agent_can_configure_depot_and_distance_limits(): void
    {
        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));

        $municipality = $this->activeMunicipality();
        $depot = Location::query()->where('place_id', 'seed-dinant-centre')->firstOrFail();

        $this->patchJson("/api/municipalities/{$municipality->id}", [
            'depot_location_id'      => $depot->id,
            'max_trip_distance_km'   => 40,
            'max_pickup_distance_km' => 30,
        ])
            ->assertOk()
            ->assertJsonPath('data.depot_location_id', $depot->id)
            ->assertJsonPath('data.depot_location.id', $depot->id)
            ->assertJsonPath('data.max_trip_distance_km', 40)
            ->assertJsonPath('data.max_pickup_distance_km', 30)
            ->assertJsonPath('data.effective_max_trip_distance_km', 40)
            ->assertJsonPath('data.effective_max_pickup_distance_km', 30);

        // Remise à null : retour aux valeurs par défaut de la config.
        config(['reservations.max_distance_km' => 50, 'reservations.max_pickup_distance_km' => 45]);

        $this->patchJson("/api/municipalities/{$municipality->id}", [
            'depot_location_id'      => null,
            'max_trip_distance_km'   => null,
            'max_pickup_distance_km' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.depot_location', null)
            ->assertJsonPath('data.effective_max_trip_distance_km', 50)
            ->assertJsonPath('data.effective_max_pickup_distance_km', 45);
    }

    public function test_regular_user_cannot_change_depot_or_limits(): void
    {
        Sanctum::actingAs($this->seededUser('user.test@bus-communal.local'));

        $this->patchJson("/api/municipalities/{$this->activeMunicipality()->id}", [
            'max_pickup_distance_km' => 500,
        ])->assertForbidden();
    }

    public function test_depot_must_be_geolocated_and_limits_bounded(): void
    {
        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));

        $unlocated = Location::create([
            'formatted_address' => 'Adresse sans coordonnées, Dinant',
            'city' => 'Dinant',
            'country' => 'Belgique',
        ]);

        $this->patchJson("/api/municipalities/{$this->activeMunicipality()->id}", [
            'depot_location_id'      => $unlocated->id,
            'max_trip_distance_km'   => 0,
            'max_pickup_distance_km' => 501,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['depot_location_id', 'max_trip_distance_km', 'max_pickup_distance_km'])
            ->assertJsonPath('errors.depot_location_id.0', 'Le lieu choisi comme dépôt doit être géolocalisé (latitude et longitude).');
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function activeMunicipality(): Municipality
    {
        return Municipality::query()->where('is_active', true)->firstOrFail();
    }
}
