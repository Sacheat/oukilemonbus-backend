<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
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

    /**
     * Les dates d'indisponibilité sont des heures locales de Bruxelles sans fuseau, comme les réservations :
     * une date reçue avec un décalage est convertie, une date sans décalage est conservée.
     */
    public function test_dates_are_stored_and_returned_as_brussels_local_time(): void
    {
        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));
        $vehicle = $this->createVehicle();

        $cases = [
            ['2026-07-10T06:00:00Z', '2026-07-10T16:00:00.000Z', '2026-07-10T08:00:00', '2026-07-10T18:00:00'], // été : UTC+2
            ['2026-07-11T08:00:00+02:00', '2026-07-11T18:00:00+02:00', '2026-07-11T08:00:00', '2026-07-11T18:00:00'],
            ['2026-12-10T07:00:00Z', '2026-12-10T17:00:00Z', '2026-12-10T08:00:00', '2026-12-10T18:00:00'], // hiver : UTC+1
            ['2026-12-11T08:00:00', '2026-12-11 18:00', '2026-12-11T08:00:00', '2026-12-11T18:00:00'], // déjà local
        ];

        foreach ($cases as [$start, $end, $expectedStart, $expectedEnd]) {
            $id = $this->postJson('/api/unavailabilities', [
                'reason_type' => 'vehicle',
                'vehicle_id' => $vehicle->id,
                'start_datetime' => $start,
                'end_datetime' => $end,
            ])
                ->assertCreated()
                ->assertJsonPath('data.start_datetime', $expectedStart)
                ->assertJsonPath('data.end_datetime', $expectedEnd)
                ->json('data.id');

            $this->getJson("/api/unavailabilities/{$id}")
                ->assertJsonPath('data.start_datetime', $expectedStart)
                ->assertJsonPath('data.end_datetime', $expectedEnd);

            $this->assertDatabaseHas('unavailabilities', [
                'id' => $id,
                'start_datetime' => str_replace('T', ' ', $expectedStart),
                'end_datetime' => str_replace('T', ' ', $expectedEnd),
            ]);
        }
    }

    public function test_update_converts_offset_dates_to_local_time_in_history(): void
    {
        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));
        $unavailability = Unavailability::create([
            'reason_type' => 'vehicle',
            'vehicle_id' => $this->createVehicle()->id,
            'start_datetime' => '2026-07-10 08:00:00',
            'end_datetime' => '2026-07-10 18:00:00',
            'created_by_user_id' => $this->seededUser('agent.test@bus-communal.local')->id,
        ]);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}", [
            'end_datetime' => '2026-07-10T18:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('data.end_datetime', '2026-07-10T20:00:00');

        $this->getJson("/api/unavailabilities/{$unavailability->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.old_end_datetime', '2026-07-10T18:00:00')
            ->assertJsonPath('data.0.new_end_datetime', '2026-07-10T20:00:00');
    }

    public function test_created_unavailability_is_returned_as_active(): void
    {
        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));

        $this->postJson('/api/unavailabilities', [
            'reason_type' => 'vehicle',
            'vehicle_id' => $this->createVehicle()->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true);
    }

    /** Une indisponibilité envoyée en UTC bloque bien les trajets aux heures locales correspondantes. */
    public function test_utc_unavailability_conflicts_with_reservation_at_matching_local_time(): void
    {
        $this->seed([TestLocationsSeeder::class, TestOrganizationsSeeder::class]);
        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));
        $driver = $this->createDriver();

        $reservation = Reservation::create([
            'requested_by_user_id' => $this->seededUser('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'trip_date' => '2026-07-10',
            'departure_time' => '16:30:00', // après 16:00Z, mais avant 18:00 à Bruxelles
            'return_time' => '17:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'driver_id' => $driver->id,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::AUTO,
        ]);

        $this->postJson('/api/unavailabilities', [
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-07-10T06:00:00Z',
            'end_datetime' => '2026-07-10T16:00:00Z',
        ])->assertCreated();

        $this->assertSame(ReservationStatus::EN_CONFLIT, $reservation->refresh()->status);
        $this->assertNull($reservation->driver_id);
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
