<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\CouncilDecisionStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\CouncilDecision;
use App\Models\Driver;
use App\Models\Location;
use App\Models\OrgRegistrationRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api/dashboard : tableau de bord de gestion (admin, agents).
 * « Aujourd'hui » = lundi 15 juin 2026 (heure de Bruxelles).
 */
class DashboardApiTest extends TestCase
{
    use DatabaseTransactions;

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

        Carbon::setTestNow('2026-06-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_internal_roles_can_open_the_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();

        foreach (['user.test@bus-communal.local', 'responsable.test@bus-communal.local', 'chauffeur.test@bus-communal.local'] as $email) {
            Sanctum::actingAs($this->user($email));
            $this->getJson('/api/dashboard')->assertForbidden();
        }

        foreach (['agent.test@bus-communal.local', 'admin.test@bus-communal.local'] as $email) {
            Sanctum::actingAs($this->user($email));
            $this->getJson('/api/dashboard')->assertOk();
        }
    }

    public function test_reservation_counters(): void
    {
        $asbl = $this->organization(OrganizationType::ASBL);
        $external = $this->organization(OrganizationType::EXTERNE);
        $school = $this->organization(OrganizationType::ECOLE_COMMUNALE);
        [$driver, $vehicle] = $this->seededFleet();

        // pending + council_pending + passengers_to_confirm
        $this->reservation($asbl, '2026-06-20', ReservationStatus::EN_ATTENTE);
        // in_conflict + council_pending (décision encore en attente) + passengers_to_confirm
        $inConflict = $this->reservation($asbl, '2026-06-22', ReservationStatus::EN_CONFLIT);
        $this->councilDecision($inConflict, CouncilDecisionStatus::EN_ATTENTE);
        // pending, Collège déjà favorable, hors fenêtre des passagers
        $approvedByCouncil = $this->reservation($external, '2026-07-10', ReservationStatus::EN_ATTENTE);
        $this->councilDecision($approvedByCouncil, CouncilDecisionStatus::APPROUVE);
        // awaiting_assignment (pas de chauffeur) + passengers_to_confirm
        $this->reservation($school, '2026-06-15', ReservationStatus::APPROUVE, ['vehicle_id' => $vehicle->id]);
        // entièrement affectée, passagers confirmés : aucun compteur
        $this->reservation($school, '2026-06-15', ReservationStatus::CONFIRME, [
            'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'passengers_confirmed_at' => now(),
        ]);
        // affectée, passagers non confirmés : passengers_to_confirm seulement
        $this->reservation($school, '2026-06-16', ReservationStatus::APPROUVE, ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);
        // J+14 : pending, mais hors fenêtre des 14 jours (15 → 28 juin) ; école : pas de Collège
        $this->reservation($school, '2026-06-29', ReservationStatus::EN_ATTENTE);
        // statuts terminaux : ignorés partout
        $this->reservation($asbl, '2026-06-17', ReservationStatus::REFUSE);
        $this->reservation($school, '2026-06-17', ReservationStatus::ANNULE);

        $requester = $this->user('user.test@bus-communal.local');
        foreach (['en_attente', 'en_attente', 'approuve'] as $status) {
            UserOrgRequest::create([
                'organization_id' => $asbl->id, 'requested_by_user_id' => User::factory()->create()->id,
                'requested_role' => 'prof', 'status' => $status,
            ]);
        }
        foreach (['en_attente', 'refuse'] as $status) {
            OrgRegistrationRequest::create([
                'request_by_user_id' => $requester->id, 'organization_name' => "Org {$status}",
                'organization_type' => 'asbl', 'official_email' => "{$status}@example.test", 'status' => $status,
            ]);
        }

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['to_process', 'today', 'week', 'fleet_alerts']])
            ->assertJsonPath('data.to_process', [
                'pending' => 3,
                'in_conflict' => 1,
                'awaiting_assignment' => 1,
                'passengers_to_confirm' => 4,
                'council_pending' => 2,
                'join_requests_pending' => 2,
                'registration_requests_pending' => 1,
            ]);
    }

    public function test_passengers_to_confirm_filter_lists_the_counted_trips(): void
    {
        $school = $this->organization(OrganizationType::ECOLE_COMMUNALE);
        $asbl = $this->organization(OrganizationType::ASBL);

        // Dans la fenêtre (15 → 28 juin), statut actif, passagers non confirmés : listées
        $tomorrow = $this->reservation($school, '2026-06-16', ReservationStatus::APPROUVE);
        $lastDay = $this->reservation($school, '2026-06-28', ReservationStatus::EN_ATTENTE);
        // Écartées : passagers confirmés, hors fenêtre (J+14, hier), statut terminal
        $this->reservation($school, '2026-06-15', ReservationStatus::CONFIRME, ['passengers_confirmed_at' => now()]);
        $this->reservation($school, '2026-06-29', ReservationStatus::EN_ATTENTE);
        $this->reservation($school, '2026-06-14', ReservationStatus::EN_ATTENTE);
        $this->reservation($asbl, '2026-06-17', ReservationStatus::REFUSE);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $listed = $this->getJson('/api/reservations?passengers_to_confirm=1&sort=asc')->assertOk();
        $this->assertSame([$tomorrow->id, $lastDay->id], collect($listed->json('data'))->pluck('id')->all());

        // Le compteur du tableau de bord suit la même règle
        $this->getJson('/api/dashboard')->assertJsonPath('data.to_process.passengers_to_confirm', $listed->json('meta.total'));

        $this->getJson('/api/reservations?passengers_to_confirm=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passengers_to_confirm');
    }

    public function test_today_trips_are_sorted_and_exclude_terminal_statuses(): void
    {
        $school = $this->organization(OrganizationType::ECOLE_COMMUNALE);
        [$driver, $vehicle] = $this->seededFleet();

        $late = $this->reservation($school, '2026-06-15', ReservationStatus::APPROUVE, ['departure_time' => '14:00:00', 'return_time' => '16:00:00']);
        $early = $this->reservation($school, '2026-06-15', ReservationStatus::CONFIRME, [
            'departure_time' => '09:00:00', 'return_time' => '11:00:00', 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id,
        ]);
        $this->reservation($school, '2026-06-15', ReservationStatus::ANNULE, ['departure_time' => '08:00:00']);
        $this->reservation($school, '2026-06-15', ReservationStatus::REFUSE, ['departure_time' => '10:00:00']);
        $this->reservation($school, '2026-06-16', ReservationStatus::CONFIRME, ['departure_time' => '07:00:00']);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $today = $this->getJson('/api/dashboard')->assertOk()->json('data.today');

        $this->assertSame('2026-06-15', $today['date']);
        $this->assertSame(2, $today['trips_count']);
        $this->assertSame([$early->id, $late->id], array_column($today['trips'], 'id'));
        $this->assertSame([
            'id' => $early->id,
            'trip_date' => '2026-06-15',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'status' => 'confirme',
            'status_label' => 'Confirmé',
            'trip_purpose' => 'Sortie',
            'organization' => ['id' => $school->id, 'name' => 'Ecole communale de Dinant'],
            'driver' => ['id' => $driver->id, 'full_name' => trim($driver->user->first_name.' '.$driver->user->last_name)],
            'vehicle' => ['id' => $vehicle->id, 'licence_plate' => $vehicle->licence_plate],
        ], $today['trips'][0]);
        $this->assertNull($today['trips'][1]['driver']);
        $this->assertNull($today['trips'][1]['vehicle']);
    }

    public function test_week_counts_active_trips_for_the_next_seven_days(): void
    {
        $school = $this->organization(OrganizationType::ECOLE_COMMUNALE);

        $this->reservation($school, '2026-06-15', ReservationStatus::CONFIRME);
        $this->reservation($school, '2026-06-15', ReservationStatus::EN_ATTENTE);
        $this->reservation($school, '2026-06-16', ReservationStatus::APPROUVE);
        $this->reservation($school, '2026-06-16', ReservationStatus::ANNULE);
        $this->reservation($school, '2026-06-21', ReservationStatus::EN_CONFLIT);
        $this->reservation($school, '2026-06-22', ReservationStatus::CONFIRME); // J+7 : hors semaine
        $this->reservation($school, '2026-06-14', ReservationStatus::CONFIRME); // hier

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.week', [
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-21',
                'trips_by_day' => [
                    ['date' => '2026-06-15', 'count' => 2],
                    ['date' => '2026-06-16', 'count' => 1],
                    ['date' => '2026-06-17', 'count' => 0],
                    ['date' => '2026-06-18', 'count' => 0],
                    ['date' => '2026-06-19', 'count' => 0],
                    ['date' => '2026-06-20', 'count' => 0],
                    ['date' => '2026-06-21', 'count' => 1],
                ],
            ]);
    }

    public function test_fleet_alerts(): void
    {
        [$driver, $vehicle] = $this->seededFleet();
        $otherVehicle = Vehicle::create(['licence_plate' => 'ALERT-02', 'capacity' => 20]);
        Vehicle::create(['licence_plate' => 'ALERT-OFF', 'capacity' => 20, 'is_active' => false]);
        $agent = $this->user('agent.test@bus-communal.local');

        $unavailable = fn (array $attributes) => Unavailability::create([
            'reason_type' => isset($attributes['driver_id']) ? 'driver' : 'vehicle',
            'created_by_user_id' => $agent->id,
            ...$attributes,
        ]);
        // Même véhicule, deux indisponibilités aujourd'hui : compté une fois.
        $unavailable(['vehicle_id' => $vehicle->id, 'start_datetime' => '2026-06-15 08:00:00', 'end_datetime' => '2026-06-15 12:00:00']);
        $unavailable(['vehicle_id' => $vehicle->id, 'start_datetime' => '2026-06-14 20:00:00', 'end_datetime' => '2026-06-16 08:00:00']);
        // Annulée, ou terminée à minuit : ignorées.
        $unavailable(['vehicle_id' => $otherVehicle->id, 'start_datetime' => '2026-06-15 08:00:00', 'end_datetime' => '2026-06-15 18:00:00', 'is_active' => false]);
        $unavailable(['vehicle_id' => $otherVehicle->id, 'start_datetime' => '2026-06-14 08:00:00', 'end_datetime' => '2026-06-15 00:00:00']);
        $unavailable(['driver_id' => $driver->id, 'start_datetime' => '2026-06-15 20:00:00', 'end_datetime' => '2026-06-15 23:00:00']);

        $expired = $this->driverWithLicence('2026-06-01');
        $soon = $this->driverWithLicence('2026-07-15'); // J+30 : inclus
        $this->driverWithLicence('2026-07-16'); // J+31 : exclu
        $this->driverWithLicence('2026-06-20', isActive: false); // chauffeur inactif : exclu

        Sanctum::actingAs($agent);

        $alerts = $this->getJson('/api/dashboard')->assertOk()->json('data.fleet_alerts');

        $this->assertSame(1, $alerts['vehicles_unavailable_today']);
        $this->assertSame(1, $alerts['drivers_unavailable_today']);
        $this->assertSame(1, $alerts['inactive_vehicles']);
        $this->assertSame([
            ['driver_id' => $expired->id, 'full_name' => 'Permis 2026-06-01', 'licence_expiry_date' => '2026-06-01'],
            ['driver_id' => $soon->id, 'full_name' => 'Permis 2026-07-15', 'licence_expiry_date' => '2026-07-15'],
        ], $alerts['licences_expiring_30_days']);
    }

    public function test_today_is_the_brussels_day(): void
    {
        Carbon::setTestNow('2026-06-15 22:30:00'); // 00:30 le 16 juin à Bruxelles
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.today.date', '2026-06-16')
            ->assertJsonPath('data.week.start_date', '2026-06-16');
    }

    private function reservation(Organization $organization, string $date, ReservationStatus $status, array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'trip_date' => $date,
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => $status,
            'approval_type' => ApprovalType::MANUEL,
            ...$attributes,
        ]);
    }

    private function councilDecision(Reservation $reservation, CouncilDecisionStatus $status): void
    {
        CouncilDecision::create([
            'reservation_id' => $reservation->id,
            'decision_status' => $status,
            'recorded_by_user_id' => $this->user('agent.test@bus-communal.local')->id,
        ]);
    }

    private function driverWithLicence(string $expiry, bool $isActive = true): Driver
    {
        $user = User::factory()->create(['first_name' => 'Permis', 'last_name' => $expiry]);

        return Driver::create([
            'user_id' => $user->id,
            'licence_number' => 'LIC-'.$expiry.($isActive ? '' : '-off'),
            'licence_expiry_date' => $expiry,
            'is_active' => $isActive,
        ]);
    }

    /** @return array{Driver, Vehicle} */
    private function seededFleet(): array
    {
        return [
            Driver::query()->where('licence_number', 'DRV-SEED-001')->with('user')->firstOrFail(),
            Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail(),
        ];
    }

    private function organization(OrganizationType $type): Organization
    {
        return Organization::query()->where('type', $type)->orderBy('id')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
