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
use App\Services\Reservations\ReservationExpirationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Clôture automatique des réservations non confirmées dont la date est dépassée
 * (commande reservations:close-expired, ReservationExpirationService) et refus d'approuver un trajet passé.
 * « Aujourd'hui » = mardi 16 juin 2026 à Bruxelles.
 */
class ReservationExpirationTest extends TestCase
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

        // 00:15 à Bruxelles le 16/06 = 22:15 UTC le 15/06 : l'heure à laquelle la tâche est planifiée
        Carbon::setTestNow('2026-06-15 22:15:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_unconfirmed_reservations_of_past_days_are_closed(): void
    {
        $organization = $this->organization(OrganizationType::ASBL);

        $pending = $this->reservation($organization, '2026-06-15', ReservationStatus::EN_ATTENTE);
        $inConflict = $this->reservation($organization, '2026-06-12', ReservationStatus::EN_CONFLIT);
        $approved = $this->reservation($organization, '2026-06-10', ReservationStatus::APPROUVE);
        $confirmed = $this->reservation($organization, '2026-06-15', ReservationStatus::CONFIRME);
        $alreadyRefused = $this->reservation($organization, '2026-06-11', ReservationStatus::REFUSE);
        $today = $this->reservation($organization, '2026-06-16', ReservationStatus::EN_ATTENTE);
        $future = $this->reservation($organization, '2026-06-30', ReservationStatus::EN_ATTENTE);

        $this->artisan('reservations:close-expired')->assertSuccessful();

        foreach ([$pending, $inConflict, $approved] as $reservation) {
            $this->assertDatabaseHas('reservations', [
                'id' => $reservation->id,
                'status' => ReservationStatus::ANNULE->value,
                'cancelled_by_user_id' => null,
                'cancellation_reason' => ReservationExpirationService::CLOSING_REASON,
            ]);
            $this->assertDatabaseHas('reservation_status_history', [
                'reservation_id' => $reservation->id,
                'new_status' => ReservationStatus::ANNULE->value,
                'changed_by_user_id' => null,
            ]);
        }

        // Confirmée (le trajet a eu lieu), déjà finale, du jour même ou future : jamais touchée
        $this->assertSame(ReservationStatus::CONFIRME, $confirmed->refresh()->status);
        $this->assertSame(ReservationStatus::REFUSE, $alreadyRefused->refresh()->status);
        $this->assertSame(ReservationStatus::EN_ATTENTE, $today->refresh()->status);
        $this->assertSame(ReservationStatus::EN_ATTENTE, $future->refresh()->status);
    }

    public function test_requester_and_internal_users_are_notified(): void
    {
        $reservation = $this->reservation($this->organization(OrganizationType::ASBL), '2026-06-12', ReservationStatus::EN_ATTENTE);

        $this->artisan('reservations:close-expired')->assertSuccessful();

        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $this->user('user.test@bus-communal.local')->id,
            'event_key' => 'reservation.expired',
        ]);

        foreach (['agent.test@bus-communal.local', 'admin.test@bus-communal.local'] as $email) {
            $this->assertDatabaseHas('notification_deliveries', [
                'notifiable_id' => $this->user($email)->id,
                'event_key' => 'reservation.expired.summary',
            ]);
        }

        $this->assertSame(ReservationStatus::ANNULE, $reservation->refresh()->status);
    }

    public function test_assigned_driver_receives_a_mission_message(): void
    {
        $reservation = $this->reservation($this->organization(OrganizationType::ASBL), '2026-06-12', ReservationStatus::APPROUVE);
        $chauffeur = $this->user('chauffeur.test@bus-communal.local');
        $reservation->update(['driver_id' => Driver::query()->where('user_id', $chauffeur->id)->firstOrFail()->id]);

        $this->artisan('reservations:close-expired')->assertSuccessful();

        $titles = fn (User $user) => DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->pluck('data')
            ->map(fn (string $data) => json_decode($data, true)['title'] ?? null)
            ->all();

        $this->assertContains('Mission annulée', $titles($chauffeur));
        $this->assertNotContains('Réservation clôturée', $titles($chauffeur));
        $this->assertContains('Réservation clôturée', $titles($this->user('user.test@bus-communal.local')));
    }

    public function test_dry_run_closes_nothing(): void
    {
        $reservation = $this->reservation($this->organization(OrganizationType::ASBL), '2026-06-12', ReservationStatus::EN_ATTENTE);

        $this->artisan('reservations:close-expired --dry-run')->assertSuccessful();

        $this->assertSame(ReservationStatus::EN_ATTENTE, $reservation->refresh()->status);
    }

    public function test_a_past_trip_can_no_longer_be_approved(): void
    {
        $reservation = $this->reservation($this->organization(OrganizationType::ECOLE_COMMUNALE), '2026-06-12', ReservationStatus::EN_ATTENTE);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->patchJson("/api/reservations/{$reservation->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        $this->assertSame(ReservationStatus::EN_ATTENTE, $reservation->refresh()->status);
    }

    private function reservation(Organization $organization, string $date, ReservationStatus $status): Reservation
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
        ]);
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
