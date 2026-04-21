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
            'enterprise_number' => '0123.456.789',
            'official_email' => 'contact@godinne-solidaire.test',
            'phone' => '082000000',
            'location_id' => $location->id,
            'contact_person_name' => 'Alice Martin',
            'contact_person_role' => 'Responsable',
            'proof_document_path' => 'proofs/asbl-godinne.pdf',
        ])
            ->assertCreated()
            ->assertJsonPath('data.confidence_note', 91)
            ->assertJsonFragment([
                'Adresse situee dans la commune de Yvoir.',
            ]);
    }

    public function test_admin_can_sync_localities_from_geonames(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $municipality = $this->activeMunicipality();

        MunicipalityLocality::create([
            'municipality_id' => $municipality->id,
            'name' => 'Noise From Previous Sync',
            'normalized_name' => 'noise from previous sync',
            'source' => 'geonames',
            'is_active' => true,
        ]);

        Http::fake([
            'https://geonames.test/searchJSON*' => Http::response([
                'geonames' => [
                    [
                        'geonameId' => 2799366,
                        'name' => 'Dinant',
                        'countryCode' => 'BE',
                    ],
                ],
            ]),
            'https://geonames.test/postalCodeSearchJSON*' => Http::response([
                'postalCodes' => [
                    [
                        'geonameId' => 2803130,
                        'placeName' => 'Anseremme',
                        'postalCode' => '5500',
                    ],
                    [
                        'geonameId' => 2801402,
                        'placeName' => 'Bouvignes-sur-Meuse',
                        'postalCode' => '5500',
                    ],
                    [
                        'geonameId' => 2803130,
                        'placeName' => 'Anseremme',
                        'postalCode' => '5500',
                    ],
                ],
            ]),
        ]);

        $this->postJson("/api/municipalities/{$municipality->id}/sync-localities")
            ->assertOk()
            ->assertJsonPath('data.external_id', '2799366')
            ->assertJsonPath('data.nis_code', '91034')
            ->assertJsonPath('data.source', 'geonames')
            ->assertJsonCount(4, 'data.localities');

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name' => 'Anseremme',
            'normalized_name' => 'anseremme',
            'external_id' => '2803130',
            'source' => 'geonames',
        ]);

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'name' => 'Bouvignes-sur-Meuse',
            'normalized_name' => 'bouvignes-sur-meuse',
            'external_id' => '2801402',
            'source' => 'geonames',
        ]);

        $this->assertDatabaseHas('municipality_localities', [
            'municipality_id' => $municipality->id,
            'normalized_name' => 'noise from previous sync',
            'source' => 'geonames',
            'is_active' => false,
        ]);
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
