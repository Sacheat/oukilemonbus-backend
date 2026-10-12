<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DriverApiTest extends TestCase
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
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $user = User::factory()->create();

        $this->postJson('/api/drivers', [
            'user_id'             => $user->id,
            'licence_number'      => 'DRV-NEW-001',
            'licence_expiry_date' => '2030-01-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.licence_number', 'DRV-NEW-001');

        $this->assertDatabaseHas('drivers', [
            'user_id'        => $user->id,
            'licence_number' => 'DRV-NEW-001',
        ]);
    }

    public function test_create_driver_fails_with_duplicate_user(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $user = $this->seededUser('chauffeur.test@bus-communal.local');

        $this->postJson('/api/drivers', [
            'user_id'             => $user->id,
            'licence_number'      => 'DRV-NEW-002',
            'licence_expiry_date' => '2030-01-01',
        ])->assertUnprocessable();
    }

    public function test_chauffeur_can_list_drivers(): void
    {
        $chauffeur = $this->seededUser('chauffeur.test@bus-communal.local');
        Sanctum::actingAs($chauffeur);

        $this->getJson('/api/drivers')
            ->assertOk()
            ->assertJsonFragment([
                'licence_number' => 'DRV-SEED-001',
            ]);
    }

    public function test_admin_can_update_driver(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $driver = $this->seededDriver('DRV-SEED-001');

        $this->patchJson("/api/drivers/{$driver->id}", [
            'licence_expiry_date' => '2035-01-01',
        ])
            ->assertOk()
            ->assertJsonPath('data.licence_expiry_date', '2035-01-01');
    }

    public function test_admin_can_deactivate_driver(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $driver = $this->seededDriver('DRV-SEED-001');

        $this->patchJson("/api/drivers/{$driver->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_show_driver(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $driver = $this->seededDriver('DRV-SEED-001');

        $this->getJson("/api/drivers/{$driver->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $driver->id)
            ->assertJsonPath('data.user.id', $driver->user_id);
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededDriver(string $licenceNumber): Driver
    {
        return Driver::query()->where('licence_number', $licenceNumber)->firstOrFail();
    }
}
