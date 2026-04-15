<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'organizations.view',
            'organizations.create',
            'organizations.update',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('admin', 'web')->givePermissionTo(Permission::all());
        Role::findOrCreate('user', 'web')->givePermissionTo(['organizations.view']);
        Role::findOrCreate('agent_communal', 'web')->givePermissionTo([
            'organizations.view',
            'organizations.create',
            'organizations.update',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_cannot_access_organization_routes(): void
    {
        $this->getJson('/api/organizations')->assertUnauthorized();
        $this->postJson('/api/organizations', [])->assertUnauthorized();
    }

    public function test_authenticated_user_without_permission_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/organizations')->assertForbidden();
    }

    public function test_user_with_view_permission_can_list_and_show_organizations(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $organization = Organization::create([
            'name' => 'ASBL Mobilite',
            'type' => OrganizationType::ASBL,
            'annual_quota' => 10,
        ]);

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'ASBL Mobilite')
            ->assertJsonPath('data.0.type', OrganizationType::ASBL->value);

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonPath('data.type_label', 'ASBL');
    }

    public function test_admin_can_create_organization(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $location = Location::create([
            'formatted_address' => 'Rue Grande 1, Dinant',
            'city' => 'Dinant',
            'country' => 'Belgique',
        ]);

        $this->postJson('/api/organizations', [
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE->value,
            'official_email' => 'direction@example.test',
            'phone_contact' => '082000000',
            'name_contact' => 'Alice Martin',
            'location_id' => $location->id,
            'annual_quota' => 25,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ecole communale de Dinant')
            ->assertJsonPath('data.type', OrganizationType::ECOLE_COMMUNALE->value)
            ->assertJsonPath('data.type_label', 'Ecole communale')
            ->assertJsonPath('data.is_priority', true)
            ->assertJsonPath('data.location.id', $location->id);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE->value,
            'is_priority' => true,
            'annual_quota' => 25,
        ]);
    }

    public function test_create_organization_rejects_invalid_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/organizations', [
            'name' => 'Organisation invalide',
            'type' => 'invalid',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_admin_can_update_organization(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $organization = Organization::create([
            'name' => 'Ancienne ecole',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'is_priority' => true,
            'annual_quota' => 10,
        ]);

        $this->patchJson("/api/organizations/{$organization->id}", [
            'name' => 'Organisation externe',
            'type' => OrganizationType::EXTERNE->value,
            'annual_quota' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Organisation externe')
            ->assertJsonPath('data.type', OrganizationType::EXTERNE->value)
            ->assertJsonPath('data.is_priority', false)
            ->assertJsonPath('data.annual_quota', 3);

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'type' => OrganizationType::EXTERNE->value,
            'is_priority' => false,
            'annual_quota' => 3,
        ]);
    }

    public function test_internal_usage_counter_cannot_be_written_through_api(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/organizations', [
            'name' => 'ASBL Protegee',
            'type' => OrganizationType::ASBL->value,
            'reservations_used_this_year' => 99,
        ])->assertCreated()
            ->assertJsonPath('data.reservations_used_this_year', 0);

        $this->assertDatabaseHas('organizations', [
            'name' => 'ASBL Protegee',
            'reservations_used_this_year' => 0,
        ]);
    }
}
