<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Reservations\ReservationAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Affectations simultanées (verrou par jour de trajet, ReservationAssignmentService::withDayLock) et filtre
 * `unassigned` de GET /reservations (file « À affecter »).
 *
 * Une seconde connexion à la base joue le rôle de l'autre agent : elle tient le verrou nommé du jour, comme une
 * affectation en cours dans une autre requête.
 */
class ReservationAssignmentConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    private const OTHER_AGENT = 'lock_probe';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
            TestOrganizationsSeeder::class,
            TestFleetSeeder::class,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config([
            'reservations.assignment_lock_wait_seconds' => 1,
            'database.connections.'.self::OTHER_AGENT => config('database.connections.'.config('database.default')),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER_AGENT)->select('SELECT RELEASE_ALL_LOCKS()');
        DB::disconnect(self::OTHER_AGENT);

        parent::tearDown();
    }

    public function test_an_assignment_waits_for_another_one_on_the_same_day_then_answers_409(): void
    {
        $reservation = $this->approvedReservation('2026-09-17');
        $this->otherAgentHoldsTheDay('2026-09-17');
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $started = microtime(true);
        $this->patchJson("/api/reservations/{$reservation->id}/assign", ['vehicle_id' => $this->vehicle()->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Une autre affectation est en cours pour ce jour : réessayez dans un instant.');

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started); // il a attendu le verrou
        $this->assertNull($reservation->refresh()->vehicle_id);

        // L'autre affectation finie : la même demande passe
        DB::connection(self::OTHER_AGENT)->select('SELECT RELEASE_ALL_LOCKS()');
        $this->patchJson("/api/reservations/{$reservation->id}/assign", ['vehicle_id' => $this->vehicle()->id])->assertOk();
        $this->assertSame($this->vehicle()->id, $reservation->refresh()->vehicle_id);
    }

    public function test_another_day_is_not_blocked_and_the_lock_is_reentrant(): void
    {
        $reservation = $this->approvedReservation('2026-09-18');
        $this->otherAgentHoldsTheDay('2026-09-17');
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->patchJson("/api/reservations/{$reservation->id}/assign", ['vehicle_id' => $this->vehicle()->id])->assertOk();

        // Réentrant : une affectation imbriquée dans une autre du même jour (ex. retour automatique après un conflit)
        $service = app(ReservationAssignmentService::class);
        $this->assertSame('ok', $service->withDayLock('2026-09-18', fn () => $service->withDayLock('2026-09-18', fn () => 'ok')));
    }

    public function test_unassigned_filter_keeps_only_reservations_missing_a_driver_or_a_vehicle(): void
    {
        $missingVehicle = $this->approvedReservation('2026-09-21');
        $complete = $this->approvedReservation('2026-09-22', ['vehicle_id' => $this->vehicle()->id]);
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $ids = collect($this->getJson('/api/reservations?statuses[]=approuve&unassigned=1&date_from=2026-09-21&date_to=2026-09-22')
            ->assertOk()
            ->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($missingVehicle->id));
        $this->assertFalse($ids->contains($complete->id));
        $this->getJson('/api/reservations?unassigned=peut-etre')->assertUnprocessable()->assertJsonValidationErrors('unassigned');
    }

    private function otherAgentHoldsTheDay(string $tripDate): void
    {
        $acquired = DB::connection(self::OTHER_AGENT)
            ->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [ReservationAssignmentService::DAY_LOCK_PREFIX.$tripDate])
            ->acquired;

        $this->assertSame(1, (int) $acquired);
    }

    private function approvedReservation(string $tripDate, array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail()->id,
            'vehicle_id' => null,
            'trip_date' => $tripDate,
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::APPROUVE,
            'approval_type' => ApprovalType::AUTO,
            ...$attributes,
        ]);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
