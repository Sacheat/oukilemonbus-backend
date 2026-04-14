<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DriverApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'drivers.view',
            'drivers.create',
            'drivers.update',
            'drivers.deactivate',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('admin', 'web')->givePermissionTo(Permission::all());
        Role::findOrCreate('chauffeur', 'web')->givePermissionTo(['drivers.view']);
        Role::findOrCreate('agent_communal', 'web')->givePermissionTo([
            'drivers.view',
            'drivers.create',
            'drivers.update',
            'drivers.deactivate',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_cannot_access_driver_routes(): void
    {
        $this->getJson('/api/drivers')->assertUnauthorized();
        $this->postJson('/api/drivers', [])->assertUnauthorized();
    }

    public function test_authenticated_user_without_permission_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/drivers')->assertForbidden();
    }

    public function test_admin_can_create_driver(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $user = User::factory()->create();

        $this->postJson('/api/drivers', [
            'user_id'             => $user->id,
            'licence_number'      => 'B-123456',
            'licence_expiry_date' => '2030-01-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.licence_number', 'B-123456');

        $this->assertDatabaseHas('drivers', [
            'user_id'        => $user->id,
            'licence_number' => 'B-123456',
        ]);
    }

    public function test_create_driver_fails_with_duplicate_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $user = User::factory()->create();
        Driver::create([
            'user_id'             => $user->id,
            'licence_number'      => 'B-123456',
            'licence_expiry_date' => '2030-01-01',
        ]);

        $this->postJson('/api/drivers', [
            'user_id'             => $user->id,
            'licence_number'      => 'B-999999',
            'licence_expiry_date' => '2030-01-01',
        ])->assertUnprocessable();
    }

    public function test_chauffeur_can_list_drivers(): void
    {
        $chauffeur = User::factory()->create();
        $chauffeur->assignRole('chauffeur');
        Sanctum::actingAs($chauffeur);

        $user = User::factory()->create();
        Driver::create([
            'user_id'             => $user->id,
            'licence_number'      => 'B-123456',
            'licence_expiry_date' => '2030-01-01',
        ]);

        $this->getJson('/api/drivers')
            ->assertOk()
            ->assertJsonPath('data.0.licence_number', 'B-123456');
    }

    public function test_admin_can_update_driver(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $user = User::factory()->create();
        $driver = Driver::create([
            'user_id'             => $user->id,
            'licence_number'      => 'B-123456',
            'licence_expiry_date' => '2030-01-01',
        ]);

        $this->patchJson("/api/drivers/{$driver->id}", [
            'licence_expiry_date' => '2035-01-01',
        ])
            ->assertOk()
            ->assertJsonPath('data.licence_expiry_date', '2035-01-01');
    }

    public function test_admin_can_deactivate_driver(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $user = User::factory()->create();
        $driver = Driver::create([
            'user_id'             => $user->id,
            'licence_number'      => 'B-123456',
            'licence_expiry_date' => '2030-01-01',
        ]);

        $this->patchJson("/api/drivers/{$driver->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_show_driver(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $user = User::factory()->create();
        $driver = Driver::create([
            'user_id'             => $user->id,
            'licence_number'      => 'B-123456',
            'licence_expiry_date' => '2030-01-01',
        ]);

        $this->getJson("/api/drivers/{$driver->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $driver->id);
    }
}
