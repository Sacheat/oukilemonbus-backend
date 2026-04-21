<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VehicleApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestFleetSeeder::class,
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
        $chauffeur = $this->seededUser('chauffeur.test@bus-communal.local');

        Sanctum::actingAs($chauffeur);

        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonFragment(['licence_plate' => '1-VJX-649']);
    }

    public function test_admin_can_create_vehicle(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        $this->postJson('/api/vehicles', [
            'licence_plate' => 'NEW-BUS-001',
            'capacity'      => 41,
        ])
            ->assertCreated()
            ->assertJsonPath('data.licence_plate', 'NEW-BUS-001')
            ->assertJsonPath('data.capacity', 41);

        $this->assertDatabaseHas('vehicles', [
            'licence_plate' => 'NEW-BUS-001',
            'capacity'      => 41,
        ]);
    }

    public function test_create_vehicle_fails_with_duplicate_licence_plate(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        $this->postJson('/api/vehicles', [
            'licence_plate' => '1-VJX-649',
            'capacity'      => 41,
        ])->assertUnprocessable();
    }

    public function test_admin_can_update_vehicle(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        $vehicle = $this->seededVehicle('1-VJX-649');

        $this->patchJson("/api/vehicles/{$vehicle->id}", [
            'capacity' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('data.capacity', 50);
    }

    public function test_admin_can_deactivate_vehicle(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        $vehicle = $this->seededVehicle('1-VJX-649');

        $this->patchJson("/api/vehicles/{$vehicle->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_show_vehicle(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');

        Sanctum::actingAs($admin);

        $vehicle = $this->seededVehicle('1-VJX-649');

        $this->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vehicle->id);
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededVehicle(string $licencePlate): Vehicle
    {
        return Vehicle::query()->where('licence_plate', $licencePlate)->firstOrFail();
    }
}
