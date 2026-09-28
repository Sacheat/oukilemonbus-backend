<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UnavailabilityApiTest extends TestCase
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

    private function createDriver(): Driver
    {
        $user = User::factory()->create();

        return Driver::create([
            'user_id' => $user->id,
            'licence_number' => 'DRV-'.fake()->unique()->numerify('######'),
            'licence_expiry_date' => '2030-01-01',
        ]);
    }

    private function createVehicle(): Vehicle
    {
        return Vehicle::create([
            'licence_plate' => strtoupper(fake()->unique()->bothify('?#??###')),
            'capacity' => 41,
        ]);
    }

    public function test_guest_cannot_access_unavailability_routes(): void
    {
        $this->getJson('/api/unavailabilities')->assertUnauthorized();
        $this->postJson('/api/unavailabilities', [])->assertUnauthorized();
    }

    public function test_chauffeur_can_only_view_unavailabilities(): void
    {
        $chauffeur = $this->seededUser('chauffeur.test@bus-communal.local');
        Sanctum::actingAs($chauffeur);

        $this->getJson('/api/unavailabilities')->assertOk();
        $this->postJson('/api/unavailabilities', [])->assertForbidden();
    }

    public function test_chauffeur_only_sees_their_own_driver_unavailabilities(): void
    {
        $chauffeur = $this->seededUser('chauffeur.test@bus-communal.local');
        $driver = $this->seededDriver($chauffeur->id);
        $otherDriver = $this->createDriver();
        $vehicle = $this->createVehicle();

        $ownUnavailability = Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
            'created_by_user_id' => $chauffeur->id,
        ]);
        $otherUnavailability = Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $otherDriver->id,
            'start_datetime' => '2026-06-11 08:00:00',
            'end_datetime' => '2026-06-11 18:00:00',
            'created_by_user_id' => $chauffeur->id,
        ]);
        $vehicleUnavailability = Unavailability::create([
            'reason_type' => 'vehicle',
            'vehicle_id' => $vehicle->id,
            'start_datetime' => '2026-06-12 08:00:00',
            'end_datetime' => '2026-06-12 18:00:00',
            'created_by_user_id' => $chauffeur->id,
        ]);

        Sanctum::actingAs($chauffeur);

        $this->getJson('/api/unavailabilities')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownUnavailability->id,
            ])
            ->assertJsonMissing([
                'id' => $otherUnavailability->id,
            ])
            ->assertJsonMissing([
                'id' => $vehicleUnavailability->id,
            ]);

        $this->getJson("/api/unavailabilities/{$ownUnavailability->id}")
            ->assertOk();

        $this->getJson("/api/unavailabilities/{$otherUnavailability->id}")
            ->assertForbidden();

        $this->getJson("/api/unavailabilities/{$vehicleUnavailability->id}")
            ->assertForbidden();
    }

    public function test_agent_can_create_driver_unavailability(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $driver = $this->createDriver();

        $this->postJson('/api/unavailabilities', [
            'reason_type' => 'driver',
            'reason' => 'Maladie',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reason_type', 'driver')
            ->assertJsonPath('data.driver_id', $driver->id);

        $this->assertDatabaseHas('unavailabilities', [
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
        ]);
    }

    public function test_agent_can_create_vehicle_unavailability(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $vehicle = $this->createVehicle();

        $this->postJson('/api/unavailabilities', [
            'reason_type' => 'vehicle',
            'reason' => 'Maintenance',
            'vehicle_id' => $vehicle->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reason_type', 'vehicle')
            ->assertJsonPath('data.vehicle_id', $vehicle->id);
    }

    public function test_create_fails_when_driver_id_missing_for_driver_type(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $this->postJson('/api/unavailabilities', [
            'reason_type' => 'driver',
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
        ])->assertUnprocessable();
    }

    public function test_create_fails_when_both_driver_and_vehicle_provided(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $driver = $this->createDriver();
        $vehicle = $this->createVehicle();

        $this->postJson('/api/unavailabilities', [
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
        ])->assertUnprocessable();
    }

    public function test_agent_can_update_unavailability(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $driver = $this->createDriver();
        $unavailability = Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
            'created_by_user_id' => $agent->id,
        ]);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}", [
            'end_datetime' => '2026-06-11 18:00:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_update_rejects_partial_start_datetime_after_existing_end_datetime(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $driver = $this->createDriver();
        $unavailability = Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
            'created_by_user_id' => $agent->id,
        ]);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}", [
            'start_datetime' => '2026-06-10 20:00:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La date de début doit être avant la date de fin.');
    }

    public function test_update_rejects_partial_end_datetime_before_existing_start_datetime(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $driver = $this->createDriver();
        $unavailability = Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
            'created_by_user_id' => $agent->id,
        ]);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}", [
            'end_datetime' => '2026-06-10 07:00:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La date de fin doit être après la date de début.');
    }

    public function test_agent_can_cancel_unavailability(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $driver = $this->createDriver();
        $unavailability = Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
            'created_by_user_id' => $agent->id,
        ]);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_agent_can_show_unavailability(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $driver = $this->createDriver();
        $unavailability = Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
            'created_by_user_id' => $agent->id,
        ]);

        $this->getJson("/api/unavailabilities/{$unavailability->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $unavailability->id);
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededDriver(int $userId): Driver
    {
        return Driver::query()->where('user_id', $userId)->firstOrFail();
    }
}
