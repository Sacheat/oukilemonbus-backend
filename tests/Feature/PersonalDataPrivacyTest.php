<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\DriverAlertStatus;
use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Enums\ReservationStatus;
use App\Enums\TripEventSource;
use App\Enums\TripEventType;
use App\Models\Driver;
use App\Models\DriverAlert;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\TripEvent;
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
 * RGPD : anonymisation d'un compte (PATCH /users/{user}/anonymize), export des données personnelles
 * (GET /auth/me/personal-data, GET /users/{user}/personal-data) et purge des positions GPS (privacy:prune).
 */
class PersonalDataPrivacyTest extends TestCase
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
        Carbon::setTestNow('2026-10-12 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Anonymisation ─────────────────────────────────────────────────────────

    public function test_admin_anonymizes_an_account_and_keeps_its_past_reservations(): void
    {
        $user = $this->user('user.test@bus-communal.local');
        $organization = $this->organization();
        $user->organizations()->syncWithoutDetaching([$organization->id => ['relationship_type' => 'prof']]);
        $user->createToken('spa');
        $pastReservation = $this->reservation(['trip_date' => '2026-09-01', 'driver_id' => null, 'vehicle_id' => null]);
        $pendingRequest = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $user->id,
            'requested_role' => 'responsable',
            'justification' => 'Je suis directrice, joignable au 0470 00 00 00',
            'status' => RequestStatus::EN_ATTENTE,
        ]);
        $admin = $this->user('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/users/{$user->id}/anonymize")
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Utilisateur')
            ->assertJsonPath('data.last_name', "supprimé #{$user->id}")
            ->assertJsonPath('data.email', "deleted-{$user->id}@anonymized.invalid")
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.active', false);

        $user->refresh();
        $this->assertTrue($user->isAnonymized());
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->organizations()->count());
        $this->assertSame(0, $user->roles()->count());
        $this->assertDatabaseHas('reservations', ['id' => $pastReservation->id, 'requested_by_user_id' => $user->id]);
        $this->assertDatabaseHas('user_org_requests', [
            'request_id' => $pendingRequest->request_id,
            'status' => RequestStatus::REFUSE->value,
            'justification' => null,
            'reviewed_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'user',
            'aggregate_id' => $user->id,
            'event_type' => 'user.anonymized',
            'actor_user_id' => $admin->id,
        ]);

        // Irréversible : ni réactivation, ni seconde anonymisation
        $this->patchJson("/api/users/{$user->id}/status", ['active' => true])->assertUnprocessable();
        $this->patchJson("/api/users/{$user->id}/anonymize")->assertUnprocessable();
    }

    public function test_anonymization_is_refused_while_the_account_has_upcoming_reservations(): void
    {
        $user = $this->user('user.test@bus-communal.local');
        $this->reservation(['trip_date' => '2026-10-20', 'driver_id' => null, 'vehicle_id' => null]);
        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));

        $this->patchJson("/api/users/{$user->id}/anonymize")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertFalse($user->refresh()->isAnonymized());
    }

    public function test_driver_with_upcoming_missions_is_refused_then_anonymized_once_reassigned(): void
    {
        $driverUser = $this->user('chauffeur.test@bus-communal.local');
        $mission = $this->reservation(['trip_date' => '2026-10-20']);
        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));

        $this->patchJson("/api/users/{$driverUser->id}/anonymize")->assertUnprocessable();

        $mission->update(['driver_id' => null]);

        $this->patchJson("/api/users/{$driverUser->id}/anonymize")->assertOk();

        $driver = Driver::query()->where('user_id', $driverUser->id)->firstOrFail();
        $this->assertSame("ANON-{$driver->id}", $driver->licence_number);
        $this->assertFalse($driver->is_active);
    }

    public function test_only_an_admin_can_anonymize_and_never_their_own_account(): void
    {
        $user = $this->user('user.test@bus-communal.local');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->patchJson("/api/users/{$user->id}/anonymize")->assertForbidden();

        $admin = $this->user('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);
        $this->patchJson("/api/users/{$admin->id}/anonymize")->assertForbidden();
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function test_user_exports_their_own_personal_data(): void
    {
        $user = $this->user('user.test@bus-communal.local');
        $reservation = $this->reservation(['trip_date' => '2026-09-01', 'driver_id' => null, 'vehicle_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me/personal-data')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="donnees-personnelles-'.$user->id.'.json"')
            ->assertJsonPath('data.profile.email', 'user.test@bus-communal.local')
            ->assertJsonPath('data.reservations_requested.0.id', $reservation->id)
            ->assertJsonPath('data.reservations_requested.0.departure_time', '09:00:00')
            ->assertJsonPath('data.driver', null)
            ->assertJsonMissingPath('data.profile.password');
    }

    public function test_driver_export_contains_positions_and_only_admins_export_other_accounts(): void
    {
        $driverUser = $this->user('chauffeur.test@bus-communal.local');
        $reservation = $this->reservation(['trip_date' => '2026-09-01']);
        $this->tripEvent($reservation, '2026-09-01 07:00:00', 50.2591);

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $this->getJson("/api/users/{$driverUser->id}/personal-data")->assertForbidden();

        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));
        $this->getJson("/api/users/{$driverUser->id}/personal-data")
            ->assertOk()
            ->assertJsonPath('data.driver.profile.licence_number', 'DRV-SEED-001')
            ->assertJsonPath('data.driver.trip_events.0.latitude', 50.2591);
    }

    // ── Purge des positions ───────────────────────────────────────────────────

    public function test_prune_clears_driver_positions_past_the_retention_and_keeps_open_alerts(): void
    {
        config(['privacy.retention.driver_positions_days' => 90]);
        $old = $this->reservation(['trip_date' => '2026-06-01']);
        $recent = $this->reservation(['trip_date' => '2026-10-01']);
        $oldEvent = $this->tripEvent($old, '2026-06-01 07:00:00', 50.1);
        $recentEvent = $this->tripEvent($recent, '2026-10-01 07:00:00', 50.2);
        $closedAlert = $this->alert(['status' => DriverAlertStatus::CLOTUREE, 'closed_at' => '2026-06-01 09:00:00']);
        $openAlert = $this->alert(['status' => DriverAlertStatus::OUVERTE, 'created_at' => '2026-06-01 08:00:00']);

        $this->artisan('privacy:prune')->assertSuccessful();

        $oldEvent->refresh();
        $this->assertNull($oldEvent->latitude);
        $this->assertNull($oldEvent->accuracy_m);
        $this->assertNull($oldEvent->position_captured_at);
        $this->assertSame('envoyee', $oldEvent->position_state->value);
        $this->assertSame(50.2, $recentEvent->refresh()->latitude);
        $this->assertNull($closedAlert->refresh()->latitude);
        $this->assertSame(50.2591, $openAlert->refresh()->latitude);
    }

    public function test_prune_is_disabled_with_a_zero_retention(): void
    {
        config(['privacy.retention.driver_positions_days' => 0]);
        $event = $this->tripEvent($this->reservation(['trip_date' => '2026-01-10']), '2026-01-10 07:00:00', 50.1);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame(50.1, $event->refresh()->latitude);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function reservation(array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => $this->organization()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => $this->driver()->id,
            'vehicle_id' => Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail()->id,
            'trip_date' => '2026-10-20',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::AUTO,
            ...$attributes,
        ]);
    }

    private function tripEvent(Reservation $reservation, string $occurredAt, float $latitude): TripEvent
    {
        return TripEvent::query()->create([
            'reservation_id' => $reservation->id,
            'driver_id' => $reservation->driver_id,
            'type' => TripEventType::DEPART_ALLER,
            'occurred_at' => $occurredAt,
            'source' => TripEventSource::CHAUFFEUR,
            'position_state' => 'envoyee',
            'latitude' => $latitude,
            'longitude' => 4.9118,
            'accuracy_m' => 15,
            'position_captured_at' => $occurredAt,
        ]);
    }

    private function alert(array $attributes): DriverAlert
    {
        $alert = DriverAlert::query()->create([
            'driver_id' => $this->driver()->id,
            'position_state' => 'envoyee',
            'latitude' => 50.2591,
            'longitude' => 4.9118,
            'accuracy_m' => 15,
            'created_by_user_id' => $this->user('chauffeur.test@bus-communal.local')->id,
            ...$attributes,
        ]);

        if (isset($attributes['created_at'])) {
            $alert->forceFill(['created_at' => $attributes['created_at']])->save();
        }

        return $alert;
    }

    private function organization(): Organization
    {
        return Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail();
    }

    private function driver(): Driver
    {
        return Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
