<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Enums\TripEventSource;
use App\Enums\TripEventType;
use App\Mail\DriverAlertOnCallMail;
use App\Models\Driver;
use App\Models\DriverAlert;
use App\Models\NotificationDelivery;
use App\Models\Location;
use App\Models\Municipality;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\TripEvent;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverAlerts\DriverAlertService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Demandes d'aide des chauffeurs (bouton « Aide ») : POST /driver-alerts, PATCH …/position, POST …/take et …/close,
 * GET /driver-alerts. Notifications aux agents et admins, email au contact de garde de la commune active.
 */
class DriverAlertApiTest extends TestCase
{
    use DatabaseTransactions;

    private const POSITION = ['state' => 'envoyee', 'latitude' => 50.2591, 'longitude' => 4.9118, 'accuracy_m' => 15];

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
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_driver_sends_an_alert_with_position_and_agents_admins_and_on_call_contact_are_warned(): void
    {
        $this->activeMunicipality(['on_call_name' => 'Garde technique', 'on_call_email' => 'garde@dinant.test']);
        $reservation = $this->confirmedReservation();
        Carbon::setTestNow('2026-06-16 07:30:00'); // 09:30 à Bruxelles, le jour du trajet
        Sanctum::actingAs($this->driverUser());

        $alertId = $this->postJson('/api/driver-alerts', [
            'reservation_id' => $reservation->id,
            'message' => 'Pneu crevé, je suis sur le bas-côté',
            'position' => self::POSITION,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ouverte')
            ->assertJsonPath('data.position.state', 'envoyee')
            ->assertJsonPath('data.position.accuracy_m', 15)
            ->assertJsonPath('data.map_point.exact', true)
            ->assertJsonPath('data.vehicle.licence_plate', '1-VJX-649')
            ->assertJsonPath('data.reservation.passengers', 11)
            ->json('data.id');

        $this->assertStringContainsString(
            'mlat=50.2591&mlon=4.9118',
            $this->getJson("/api/driver-alerts/{$alertId}")->assertOk()->json('data.map_links.openstreetmap')
        );

        foreach (['agent.test@bus-communal.local', 'admin.test@bus-communal.local'] as $email) {
            foreach (['database', 'mail'] as $channel) {
                $this->assertDatabaseHas('notification_deliveries', [
                    'notifiable_id' => $this->user($email)->id,
                    'event_key' => 'driver_alert.created',
                    'channel' => $channel,
                ]);
            }
        }

        // L'email mène à la page de l'alerte (et non à la réservation), avec la carte en lien secondaire
        $presenter = app(\App\Support\Mail\EmailPresenter::class);
        $this->assertStringEndsWith(
            "/gestion/alertes/{$alertId}",
            $presenter->link($this->user('agent.test@bus-communal.local'), $reservation, ['path' => "/gestion/alertes/{$alertId}", 'label' => 'Voir', 'overrides_reservation' => true])['url']
        );

        Mail::assertQueued(DriverAlertOnCallMail::class, fn (DriverAlertOnCallMail $mail) => $mail->hasTo('garde@dinant.test')
            && $mail->alert->id === $alertId);
    }

    public function test_without_position_the_last_known_place_comes_from_the_trip_progress(): void
    {
        $this->activeMunicipality();
        $reservation = $this->confirmedReservation();
        Carbon::setTestNow('2026-06-16 07:30:00');
        TripEvent::query()->create([
            'reservation_id' => $reservation->id,
            'driver_id' => $reservation->driver_id,
            'type' => TripEventType::DEPART_ALLER,
            'occurred_at' => now(),
            'source' => TripEventSource::CHAUFFEUR,
            'position_state' => 'refusee',
        ]);
        Sanctum::actingAs($this->driverUser());

        $response = $this->postJson('/api/driver-alerts', ['reservation_id' => $reservation->id, 'position' => ['state' => 'refusee']])
            ->assertCreated()
            ->assertJsonPath('data.position.state', 'refusee')
            ->assertJsonPath('data.position.latitude', null)
            ->assertJsonPath('data.trip_progress', 'en_route_aller');

        $this->assertStringStartsWith('En route vers la destination', $response->json('data.fallback_location.label'));
        $this->assertStringContainsString('Partage de position refusé', $response->json('data.summary'));
        Mail::assertNothingQueued(); // pas de contact de garde renseigné
    }

    public function test_only_the_driver_of_the_trip_can_send_an_alert_for_it(): void
    {
        $reservation = $this->confirmedReservation(['driver_id' => $this->otherDriver()->id]);

        Sanctum::actingAs($this->driverUser());
        $this->postJson('/api/driver-alerts', ['reservation_id' => $reservation->id, 'position' => self::POSITION])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation_id');

        // Sans trajet (entre deux missions) : accepté
        $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->assertCreated()->assertJsonPath('data.reservation', null);

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->assertForbidden();
    }

    public function test_an_idempotency_key_prevents_a_double_alert(): void
    {
        Sanctum::actingAs($this->driverUser());
        $body = ['position' => self::POSITION, 'message' => 'Panne moteur'];

        $first = $this->postJson('/api/driver-alerts', $body, ['Idempotency-Key' => 'aide-1'])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/driver-alerts', $body, ['Idempotency-Key' => 'aide-1'])->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('driver_alerts', 1);
    }

    public function test_agent_takes_then_closes_the_alert_and_the_driver_is_warned(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->driverUser());
        $alertId = $this->postJson('/api/driver-alerts', ['reservation_id' => $reservation->id, 'position' => self::POSITION])->json('data.id');

        // Le chauffeur renvoie sa position ; il ne peut pas prendre en charge
        $this->patchJson("/api/driver-alerts/{$alertId}/position", ['position' => [...self::POSITION, 'latitude' => 50.26]])
            ->assertOk()
            ->assertJsonPath('data.position.latitude', 50.26)
            ->assertJsonPath('data.position.updates', 1);
        $this->postJson("/api/driver-alerts/{$alertId}/take")->assertForbidden();

        $agent = $this->user('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);
        $this->postJson("/api/driver-alerts/{$alertId}/take")
            ->assertOk()
            ->assertJsonPath('data.status', 'prise_en_charge')
            ->assertJsonPath('data.handled_by', 'Bruno Agent');
        $this->postJson("/api/driver-alerts/{$alertId}/take")->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $this->driverUser()->id,
            'event_key' => 'driver_alert.taken',
            'channel' => 'database',
        ]);

        $this->postJson("/api/driver-alerts/{$alertId}/close", [])->assertUnprocessable()->assertJsonValidationErrors('closing_note');
        $this->postJson("/api/driver-alerts/{$alertId}/close", ['closing_note' => 'Dépanneuse envoyée, bus de remplacement à 11:30.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cloturee')
            ->assertJsonPath('data.closed_by', 'Bruno Agent');

        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $this->driverUser()->id,
            'event_key' => 'driver_alert.closed',
            'channel' => 'database',
        ]);

        Sanctum::actingAs($this->driverUser());
        $this->patchJson("/api/driver-alerts/{$alertId}/position", ['position' => self::POSITION])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_list_shows_own_alerts_to_drivers_and_open_ones_first_to_agents(): void
    {
        Sanctum::actingAs($this->driverUser());
        $own = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id');

        Sanctum::actingAs($this->otherDriver()->user);
        $other = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson("/api/driver-alerts/{$own}/close", ['closing_note' => 'Fausse alerte.'])->assertOk();

        $this->getJson('/api/driver-alerts')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $other); // ouverte avant clôturée
        $this->getJson('/api/driver-alerts?statuses[]=ouverte&statuses[]=prise_en_charge&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        Sanctum::actingAs($this->driverUser());
        $this->getJson('/api/driver-alerts')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own);
        $this->getJson("/api/driver-alerts/{$other}")->assertForbidden();
    }

    public function test_open_alerts_come_before_taken_ones_even_when_older(): void
    {
        Sanctum::actingAs($this->driverUser());
        $open = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id');
        Sanctum::actingAs($this->otherDriver()->user);
        $taken = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id');

        // La demande ouverte est la plus ancienne ; l'autre, plus récente, est déjà prise en charge
        DriverAlert::query()->whereKey($open)->update(['created_at' => now()->subMinutes(30)]);
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson("/api/driver-alerts/{$taken}/take")->assertOk();

        $this->getJson('/api/driver-alerts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $open) // personne ne s'en occupe : en tête malgré son ancienneté
            ->assertJsonPath('data.1.id', $taken);
    }

    public function test_two_agents_taking_the_same_alert_at_once_only_one_wins(): void
    {
        Sanctum::actingAs($this->driverUser());
        $alertId = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id');

        // Les deux agents ont chargé l'alerte « ouverte » avant que l'un d'eux ne clique
        $seenByFirst = DriverAlert::query()->findOrFail($alertId);
        $seenBySecond = DriverAlert::query()->findOrFail($alertId);
        $service = app(DriverAlertService::class);

        $service->take($seenByFirst, $this->user('agent.test@bus-communal.local'));

        try {
            $service->take($seenBySecond, $this->user('admin.test@bus-communal.local'));
            $this->fail('La seconde prise en charge aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('déjà prise en charge par Bruno Agent', $exception->errors()['status'][0]);
        }

        $this->assertSame($this->user('agent.test@bus-communal.local')->id, DriverAlert::query()->findOrFail($alertId)->handled_by_user_id);
        $this->assertSame(1, NotificationDelivery::query()
            ->where('notifiable_id', $this->driverUser()->id)
            ->where('event_key', 'driver_alert.taken')
            ->where('channel', 'database')
            ->count());

        // Double clôture : la seconde est refusée et ne remplace pas la note
        $service->close(DriverAlert::query()->findOrFail($alertId), $this->user('agent.test@bus-communal.local'), 'Dépanneuse envoyée.');
        $this->expectException(ValidationException::class);
        $service->close($seenBySecond, $this->user('admin.test@bus-communal.local'), 'Autre note.');
    }

    public function test_agents_are_warned_when_the_on_call_email_finally_fails(): void
    {
        $this->activeMunicipality(['on_call_name' => 'Garde technique', 'on_call_email' => 'garde@dinant.test', 'on_call_phone' => '082 40 40 40']);
        Sanctum::actingAs($this->driverUser());
        $alert = DriverAlert::query()->findOrFail($this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id'));

        // Ce que fait le worker après la dernière tentative ratée
        (new DriverAlertOnCallMail($alert, 'Demande d’aide', 'Texte', null))->failed(new RuntimeException('SMTP 535'));

        foreach (['agent.test@bus-communal.local', 'admin.test@bus-communal.local'] as $email) {
            $this->assertDatabaseHas('notification_deliveries', [
                'notifiable_id' => $this->user($email)->id,
                'event_key' => 'driver_alert.on_call_failed',
                'channel' => 'database',
            ]);
        }
        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'driver_alert',
            'aggregate_id' => $alert->id,
            'event_type' => 'driver_alert.on_call_failed',
        ]);
        $this->assertSame(3, (new DriverAlertOnCallMail($alert, 'x', 'y', null))->tries);
    }

    public function test_a_second_press_returns_the_alert_in_progress_without_warning_everyone_again(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');
        Sanctum::actingAs($this->driverUser());

        $first = $this->postJson('/api/driver-alerts', ['position' => self::POSITION, 'message' => 'Pneu crevé'])
            ->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->json('data.id');

        // Nouvel appui sans clé anti-doublon : même alerte, position et message mis à jour, pas de nouvel avertissement
        $this->postJson('/api/driver-alerts', ['position' => [...self::POSITION, 'latitude' => 50.27], 'message' => 'Un enfant a froid'])
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.position.latitude', 50.27)
            ->assertJsonPath('data.position.updates', 1);

        $this->assertDatabaseCount('driver_alerts', 1);
        $this->assertStringContainsString('Pneu crevé', DriverAlert::query()->findOrFail($first)->message);
        $this->assertStringContainsString('Un enfant a froid', DriverAlert::query()->findOrFail($first)->message);
        $this->assertSame(1, NotificationDelivery::query()
            ->where('notifiable_id', $agent->id)->where('event_key', 'driver_alert.created')->where('channel', 'database')->count());

        // Une fois clôturée, un nouvel appui crée une nouvelle alerte
        Sanctum::actingAs($agent);
        $this->postJson("/api/driver-alerts/{$first}/close", ['closing_note' => 'Réglé.'])->assertOk();
        Sanctum::actingAs($this->driverUser());
        $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->assertCreated();
        $this->assertDatabaseCount('driver_alerts', 2);
    }

    public function test_an_alert_is_attached_only_to_a_trip_that_takes_place_today(): void
    {
        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-20 08:00:00'); // le trajet du 16/06 est passé

        $past = $this->confirmedReservation();
        $this->postJson('/api/driver-alerts', ['reservation_id' => $past->id, 'position' => self::POSITION])
            ->assertCreated()
            ->assertJsonPath('data.reservation', null)
            ->assertJsonPath('data.trip_progress', null);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson('/api/driver-alerts/'.DriverAlert::query()->latest('id')->value('id').'/close', ['closing_note' => 'Test.'])->assertOk();

        // Annulé le jour même : pas de trajet non plus ; confirmé le jour même : rattaché
        Sanctum::actingAs($this->driverUser());
        $cancelled = $this->confirmedReservation(['trip_date' => '2026-06-20', 'status' => ReservationStatus::ANNULE]);
        $alertId = $this->postJson('/api/driver-alerts', ['reservation_id' => $cancelled->id, 'position' => self::POSITION])
            ->assertCreated()
            ->assertJsonPath('data.reservation', null)
            ->json('data.id');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson("/api/driver-alerts/{$alertId}/close", ['closing_note' => 'Test.'])->assertOk();

        Sanctum::actingAs($this->driverUser());
        $today = $this->confirmedReservation(['trip_date' => '2026-06-20', 'departure_time' => '08:00:00', 'return_time' => '18:00:00']);
        $this->postJson('/api/driver-alerts', ['reservation_id' => $today->id, 'position' => self::POSITION])
            ->assertCreated()
            ->assertJsonPath('data.reservation.id', $today->id);
    }

    public function test_a_notification_failure_never_turns_a_recorded_action_into_an_error(): void
    {
        $this->mock(NotificationDispatchService::class, fn ($mock) => $mock
            ->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('File de notifications en panne')));

        Sanctum::actingAs($this->driverUser());
        $alertId = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->assertCreated()->json('data.id');

        $agent = $this->user('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);
        $this->postJson("/api/driver-alerts/{$alertId}/take")->assertOk()->assertJsonPath('data.status', 'prise_en_charge');
        $this->postJson("/api/driver-alerts/{$alertId}/close", ['closing_note' => 'Réglé par téléphone.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cloturee');
    }

    public function test_closing_an_alert_nobody_took_marks_the_closer_as_handler(): void
    {
        Sanctum::actingAs($this->driverUser());
        $alertId = $this->postJson('/api/driver-alerts', ['position' => ['state' => 'refusee']])
            ->assertCreated()
            ->assertJsonPath('data.position.state', 'refusee')
            ->json('data.id');

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson("/api/driver-alerts/{$alertId}/close", ['closing_note' => 'Fausse alerte.'])
            ->assertOk()
            ->assertJsonPath('data.handled_by', 'Bruno Agent')
            ->assertJsonPath('data.closed_by', 'Bruno Agent');
        $this->assertNotNull(DriverAlert::query()->findOrFail($alertId)->handled_at);

        // Un autre chauffeur ne peut pas envoyer de position sur cette alerte
        Sanctum::actingAs($this->otherDriver()->user);
        $this->patchJson("/api/driver-alerts/{$alertId}/position", ['position' => self::POSITION])->assertForbidden();
    }

    public function test_an_alert_nobody_takes_is_reminded_once_after_ten_minutes(): void
    {
        $this->activeMunicipality(['on_call_name' => 'Garde technique', 'on_call_email' => 'garde@dinant.test']);
        $agent = $this->user('agent.test@bus-communal.local');
        Carbon::setTestNow('2026-06-16 07:00:00');

        Sanctum::actingAs($this->driverUser());
        $waiting = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id');
        Sanctum::actingAs($this->otherDriver()->user);
        $taken = $this->postJson('/api/driver-alerts', ['position' => self::POSITION])->json('data.id');
        Sanctum::actingAs($agent);
        $this->postJson("/api/driver-alerts/{$taken}/take")->assertOk();
        Mail::fake(); // on ne compte que les emails de la relance

        $reminders = fn (): int => NotificationDelivery::query()
            ->where('notifiable_id', $agent->id)->where('event_key', 'driver_alert.reminder')->where('channel', 'database')->count();

        // 9 minutes : trop tôt
        Carbon::setTestNow('2026-06-16 07:09:00');
        $this->artisan('driver-alerts:remind')->assertSuccessful();
        $this->assertSame(0, $reminders());

        // 10 minutes : relance de l'alerte toujours ouverte, pas de celle prise en charge
        Carbon::setTestNow('2026-06-16 07:10:30');
        $this->artisan('driver-alerts:remind')->expectsOutputToContain('1 demande(s)')->assertSuccessful();
        $this->assertSame(1, $reminders());
        $this->assertNotNull(DriverAlert::query()->findOrFail($waiting)->reminded_at);
        $this->assertNull(DriverAlert::query()->findOrFail($taken)->reminded_at);
        Mail::assertQueued(DriverAlertOnCallMail::class, 1);

        // Une seule relance par alerte
        Carbon::setTestNow('2026-06-16 07:30:00');
        $this->artisan('driver-alerts:remind')->expectsOutputToContain('0 demande(s)')->assertSuccessful();
        $this->assertSame(1, $reminders());

        $this->getJson("/api/driver-alerts/{$waiting}")->assertOk()->assertJsonPath('data.reminded_at', '2026-06-16T07:10:30.000000Z');
    }

    private function activeMunicipality(array $attributes = []): Municipality
    {
        Municipality::query()->update(['is_active' => false]);

        return Municipality::create(['name' => 'Dinant', 'country' => 'BE', 'is_active' => true, ...$attributes]);
    }

    private function confirmedReservation(array $attributes = []): Reservation
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

    private function driverUser(): User
    {
        return $this->user('chauffeur.test@bus-communal.local');
    }

    private function otherDriver(): Driver
    {
        return Driver::query()->where('licence_number', 'DRV-SEED-002')->with('user')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
