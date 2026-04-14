<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VehicleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'vehicles.view',
            'vehicles.create',
            'vehicles.update',
            'vehicles.deactivate',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('admin', 'web')->givePermissionTo(Permission::all());
        Role::findOrCreate('user', 'web')->givePermissionTo([]);
        Role::findOrCreate('chauffeur', 'web')->givePermissionTo(['vehicles.view']);
        Role::findOrCreate('agent_communal', 'web')->givePermissionTo([
            'vehicles.view',
            'vehicles.create',
            'vehicles.update',
            'vehicles.deactivate',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_cannot_access_vehicle_routes(): void
    {
        $this->getJson('/api/vehicles')->assertUnauthorized();
        $this->postJson('/api/vehicles', [])->assertUnauthorized();
    }

    public function test_authenticated_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        Sanctum::actingAs($user);

        $this->getJson('/api/vehicles')->assertForbidden();
    }

    public function test_chauffeur_can_list_vehicles(): void
    {
        $chauffeur = User::factory()->create();
        $chauffeur->assignRole('chauffeur');

        Sanctum::actingAs($chauffeur);

        Vehicle::create(['licence_plate' => '1-VJX-649', 'capacity' => 41]);

        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonPath('data.0.licence_plate', '1-VJX-649');
    }

    public function test_admin_can_create_vehicle(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/vehicles', [
            'licence_plate' => '1-VJX-649',
            'capacity'      => 41,
        ])
            ->assertCreated()
            ->assertJsonPath('data.licence_plate', '1-VJX-649')
            ->assertJsonPath('data.capacity', 41);

        $this->assertDatabaseHas('vehicles', [
            'licence_plate' => '1-VJX-649',
            'capacity'      => 41,
        ]);
    }

    public function test_create_vehicle_fails_with_duplicate_licence_plate(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        Vehicle::create(['licence_plate' => '1-VJX-649', 'capacity' => 41]);

        $this->postJson('/api/vehicles', [
            'licence_plate' => '1-VJX-649',
            'capacity'      => 41,
        ])->assertUnprocessable();
    }

    public function test_admin_can_update_vehicle(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $vehicle = Vehicle::create(['licence_plate' => '1-VJX-649', 'capacity' => 41]);

        $this->patchJson("/api/vehicles/{$vehicle->id}", [
            'capacity' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('data.capacity', 50);
    }

    public function test_admin_can_deactivate_vehicle(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $vehicle = Vehicle::create(['licence_plate' => '1-VJX-649', 'capacity' => 41]);

        $this->patchJson("/api/vehicles/{$vehicle->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_show_vehicle(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $vehicle = Vehicle::create(['licence_plate' => '1-VJX-649', 'capacity' => 41]);

        $this->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vehicle->id);
    }
}
