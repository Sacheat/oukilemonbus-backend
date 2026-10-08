<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Municipality;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WeeklyPlanningDispatch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Planning hebdomadaire envoyé automatiquement (planning:send-weekly, WeeklyPlanningService).
 * Semaine testée : lundi 15 → dimanche 21 juin 2026 ; envoi par défaut le vendredi 12 juin à 12:00 (Bruxelles, UTC+2).
 * Carbon::setTestNow attend une heure UTC.
 */
class WeeklyPlanningTest extends TestCase
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
        Storage::fake('local');
        Storage::fake(config('filesystems.default'));

        // Commune active sans réglage : valeurs par défaut de la config
        Municipality::query()->update(['is_active' => false]);
        Municipality::create(['name' => 'Dinant', 'country' => 'BE', 'source' => 'manual', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_next_week_is_sent_once_at_the_moment_to_agents_and_admins(): void
    {
        $this->reservation(['trip_date' => '2026-06-16']);
        $this->reservation(['trip_date' => '2026-06-17', 'status' => ReservationStatus::APPROUVE]);
        $this->reservation(['trip_date' => '2026-06-18', 'status' => ReservationStatus::EN_ATTENTE]); // pas dans le fichier
        $this->reservation(['trip_date' => '2026-06-23']); // semaine suivante : pas dans ce planning

        // Vendredi 11:59 : trop tôt
        Carbon::setTestNow('2026-06-12 09:59:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('0 planning(s)')->assertSuccessful();

        // Vendredi 12:00 : version 1 de la semaine du 15/06
        Carbon::setTestNow('2026-06-12 10:00:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('1 planning(s)')->assertSuccessful();

        $dispatch = WeeklyPlanningDispatch::query()->sole();
        $this->assertSame('2026-06-15', $dispatch->week_start->toDateString());
        $this->assertSame(1, $dispatch->version);
        $this->assertSame(2, $dispatch->trip_count);
        $this->assertSame(1, $dispatch->pending_count);

        // Un seul fichier, quel que soit le nombre de destinataires
        $document = Document::query()->findOrFail($dispatch->document_id);
        $this->assertSame(DocumentType::PLANNING, $document->document_type);
        $this->assertSame('2026-06-15', $document->service_date?->toDateString());
        $this->assertSame(1, Document::query()->where('document_type', DocumentType::PLANNING)->count());

        foreach (['admin.test@bus-communal.local', 'agent.test@bus-communal.local'] as $email) {
            $this->assertDatabaseHas('notification_deliveries', [
                'notifiable_id' => $this->user($email)->id,
                'event_key' => 'planning.weekly.sent',
                'channel' => 'database',
            ]);
        }
        $this->assertDatabaseMissing('notification_deliveries', [
            'notifiable_id' => $this->user('chauffeur.test@bus-communal.local')->id,
            'event_key' => 'planning.weekly.sent',
        ]);

        // Jamais deux fois
        Carbon::setTestNow('2026-06-12 10:15:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('0 planning(s)')->assertSuccessful();
        $this->assertSame(1, WeeklyPlanningDispatch::query()->count());
    }

    public function test_a_corrected_version_follows_a_change_at_most_once_an_hour_until_the_end_of_the_week(): void
    {
        $trip = $this->reservation(['trip_date' => '2026-06-16']);
        Carbon::setTestNow('2026-06-12 10:00:00');
        $this->artisan('planning:send-weekly')->assertSuccessful();

        // Rien n'a changé : pas de nouvelle version
        Carbon::setTestNow('2026-06-12 12:00:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('0 planning(s)')->assertSuccessful();

        // Le trajet change à 10:20 : trop tôt pour renvoyer à 10:30 (moins d'une heure après la version 1)…
        Carbon::setTestNow('2026-06-12 10:20:00');
        $trip->update(['departure_time' => '10:00:00', 'return_time' => '12:00:00']);
        Carbon::setTestNow('2026-06-12 10:30:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('0 planning(s)')->assertSuccessful();

        // … mais la version 2 part au passage suivant l'heure
        Carbon::setTestNow('2026-06-12 11:00:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('1 planning(s)')->assertSuccessful();
        $this->assertSame(2, WeeklyPlanningDispatch::query()->max('version'));
        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $this->user('agent.test@bus-communal.local')->id,
            'event_key' => 'planning.weekly.updated',
        ]);

        // Pendant la semaine elle-même, un changement est encore envoyé…
        Carbon::setTestNow('2026-06-15 06:00:00');
        $trip->update(['status' => ReservationStatus::ANNULE]);
        Carbon::setTestNow('2026-06-15 08:00:00');
        $this->artisan('planning:send-weekly')->assertSuccessful();
        $this->assertSame(3, WeeklyPlanningDispatch::query()->whereDate('week_start', '2026-06-15')->max('version'));

        // … plus une fois la semaine terminée
        $this->reservation(['trip_date' => '2026-06-19']);
        Carbon::setTestNow('2026-06-22 08:00:00');
        $this->artisan('planning:send-weekly')->assertSuccessful();
        $this->assertSame(3, WeeklyPlanningDispatch::query()->whereDate('week_start', '2026-06-15')->max('version'));
    }

    public function test_corrected_versions_of_two_weeks_sent_in_the_same_pass_both_reach_everyone(): void
    {
        // Le samedi, la semaine en cours et la suivante sont toutes deux ouvertes aux corrections
        $thisWeekTrip = $this->reservation(['trip_date' => '2026-06-10']);
        $nextWeekTrip = $this->reservation(['trip_date' => '2026-06-16']);
        Carbon::setTestNow('2026-06-05 10:00:00');
        $this->artisan('planning:send-weekly')->assertSuccessful();
        Carbon::setTestNow('2026-06-12 10:00:00');
        $this->artisan('planning:send-weekly')->assertSuccessful();

        Carbon::setTestNow('2026-06-13 07:00:00');
        $thisWeekTrip->update(['departure_time' => '10:00:00', 'return_time' => '12:00:00']);
        $nextWeekTrip->update(['departure_time' => '10:00:00', 'return_time' => '12:00:00']);
        Carbon::setTestNow('2026-06-13 08:00:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('2 planning(s)')->assertSuccessful();

        // Une notification par semaine : l'anti-spam ne confond pas les deux versions corrigées
        $this->assertSame(2, NotificationDelivery::query()
            ->where('notifiable_id', $this->user('agent.test@bus-communal.local')->id)
            ->where('event_key', 'planning.weekly.updated')->where('channel', 'database')
            ->count());
    }

    public function test_moving_a_trip_to_another_organization_sends_a_corrected_version(): void
    {
        $trip = $this->reservation(['trip_date' => '2026-06-16']);
        Carbon::setTestNow('2026-06-12 10:00:00');
        $this->artisan('planning:send-weekly')->assertSuccessful();

        // Les colonnes Organisation et Contact du fichier changent
        $trip->update(['organization_id' => Organization::query()->whereKeyNot($trip->organization_id)->orderBy('id')->firstOrFail()->id]);
        Carbon::setTestNow('2026-06-12 11:00:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('1 planning(s)')->assertSuccessful();
        $this->assertSame(2, WeeklyPlanningDispatch::query()->whereDate('week_start', '2026-06-15')->max('version'));
    }

    public function test_the_owner_receives_it_alone_by_default_and_the_others_can_opt_in(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');
        $admin = $this->user('admin.test@bus-communal.local');
        $municipality = Municipality::query()->where('is_active', true)->sole();

        // L'admin désigne l'agent : historique et agent prévenu
        Sanctum::actingAs($admin);
        $this->patchJson("/api/municipalities/{$municipality->id}", ['weekly_planning_owner_user_id' => $agent->id])
            ->assertOk()
            ->assertJsonPath('data.weekly_planning_owner.id', $agent->id);
        $this->assertDatabaseHas('notification_deliveries', ['notifiable_id' => $agent->id, 'event_key' => 'planning.weekly.owner_assigned']);
        $this->assertDatabaseHas('domain_events', ['aggregate_id' => $municipality->id, 'event_type' => 'weekly_planning.owner_changed']);

        // L'admin choisit quand même de le recevoir (remplacement en cas d'absence) ; un autre agent, non
        $this->patchJson('/api/notifications/preferences', ['weekly_planning_opt_in' => true])->assertSuccessful()
            ->assertJsonPath('data.weekly_planning_opt_in', true);

        $this->reservation(['trip_date' => '2026-06-16']);
        Carbon::setTestNow('2026-06-12 10:00:00');
        $this->artisan('planning:send-weekly')->assertSuccessful();

        $received = NotificationDelivery::query()
            ->where('event_key', 'planning.weekly.sent')->where('channel', 'database')
            ->pluck('notifiable_id')->sort()->values()->all();
        $this->assertSame(collect([$agent->id, $admin->id])->sort()->values()->all(), $received);
        $this->assertSame(2, WeeklyPlanningDispatch::query()->sole()->recipient_count);

        // Responsable désactivé : retour à « tous » pour que le planning ne se perde pas
        $agent->update(['active' => false]);
        $this->assertTrue(app(\App\Services\Planning\WeeklyPlanningService::class)->recipients()
            ->contains(fn (User $user): bool => $user->email === 'reviewer.test@bus-communal.local'));
    }

    public function test_only_an_admin_sets_the_sending_and_it_can_be_moved_or_turned_off(): void
    {
        $municipality = Municipality::query()->where('is_active', true)->sole();
        $this->reservation(['trip_date' => '2026-06-16']);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->patchJson("/api/municipalities/{$municipality->id}", ['weekly_planning_send_day' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weekly_planning_send_day');

        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));
        $this->patchJson("/api/municipalities/{$municipality->id}", [
            'weekly_planning_owner_user_id' => $this->user('chauffeur.test@bus-communal.local')->id,
            'weekly_planning_send_time' => '25:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['weekly_planning_owner_user_id', 'weekly_planning_send_time']);

        // Coupé : rien ne part le vendredi
        $this->patchJson("/api/municipalities/{$municipality->id}", ['weekly_planning_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.effective_weekly_planning_enabled', false);
        Carbon::setTestNow('2026-06-12 10:00:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('0 planning(s)')->assertSuccessful();

        // Rallumé et avancé au jeudi 08:00 : part le jeudi 11/06 à 08:00
        $this->patchJson("/api/municipalities/{$municipality->id}", [
            'weekly_planning_enabled' => true,
            'weekly_planning_send_day' => 4,
            'weekly_planning_send_time' => '08:00',
        ])->assertOk()->assertJsonPath('data.effective_weekly_planning_send_day', 4);
        WeeklyPlanningDispatch::query()->delete();

        Carbon::setTestNow('2026-06-11 05:59:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('0 planning(s)')->assertSuccessful();
        Carbon::setTestNow('2026-06-11 06:00:00');
        $this->artisan('planning:send-weekly')->expectsOutputToContain('1 planning(s)')->assertSuccessful();
    }

    private function reservation(array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail()->id,
            'vehicle_id' => Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail()->id,
            'trip_date' => '2026-06-16',
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

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
