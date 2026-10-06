<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Enums\TripEventSource;
use App\Enums\TripReportOutcome;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\TripEvent;
use App\Models\TripReport;
use App\Models\User;
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
 * Avancement d'un trajet (« Ma journée ») : POST /reservations/{id}/trip-events, GET …/trip-progress, GET /trip-progress.
 * Trajet de test : mardi 16 juin 2026, départ 09:00, retour 11:00 (heure de Bruxelles, UTC+2 en été).
 * Carbon::setTestNow attend une heure UTC.
 */
class TripProgressApiTest extends TestCase
{
    use DatabaseTransactions;

    private const POSITION = ['state' => 'envoyee', 'latitude' => 50.2591, 'longitude' => 4.9118, 'accuracy_m' => 15];

    private const NO_POSITION = ['state' => 'refusee'];

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_driver_records_the_four_steps_and_the_last_arrival_creates_the_realised_report(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->driverUser());

        Carbon::setTestNow('2026-06-16 07:02:00'); // 09:02 à Bruxelles
        $this->record($reservation, 'depart_aller')
            ->assertCreated()
            ->assertJsonPath('data.local_time', '09:02')
            ->assertJsonPath('data.source', 'chauffeur')
            ->assertJsonPath('data.position_state', 'envoyee')
            ->assertJsonPath('meta.progress.state', 'en_route_aller')
            ->assertJsonPath('meta.progress.next_step', 'arrivee_aller');

        Carbon::setTestNow('2026-06-16 07:25:00');
        $this->record($reservation, 'arrivee_aller')->assertCreated()->assertJsonPath('meta.progress.state', 'sur_place');

        Carbon::setTestNow('2026-06-16 09:05:00'); // 11:05 : départ du retour
        $this->record($reservation, 'depart_retour')->assertCreated()->assertJsonPath('meta.progress.state', 'en_route_retour');

        Carbon::setTestNow('2026-06-16 09:30:00');
        $this->record($reservation, 'arrivee_retour', ['actual_nb_children' => 9, 'actual_nb_adults' => 1])
            ->assertCreated()
            ->assertJsonPath('meta.progress.state', 'termine')
            ->assertJsonPath('meta.progress.next_step', null)
            ->assertJsonPath('meta.trip_report.outcome', 'realise')
            ->assertJsonPath('meta.trip_report.actual_departure_time', '09:02')
            ->assertJsonPath('meta.trip_report.actual_return_time', '11:05')
            ->assertJsonPath('meta.trip_report.actual_nb_children', 9);

        $this->assertSame(TripReportOutcome::REALISE, $reservation->refresh()->tripReport->outcome);
        $this->assertSame(ReservationStatus::CONFIRME, $reservation->status);
        $this->assertDatabaseCount('trip_events', 4);
    }

    public function test_steps_must_follow_the_order_and_a_repeated_step_changes_nothing(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-16 07:00:00');

        $this->record($reservation, 'arrivee_aller')->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->record($reservation, 'depart_aller')->assertCreated();
        Carbon::setTestNow('2026-06-16 07:10:00');
        $this->record($reservation, 'depart_aller')
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('data.local_time', '09:00');

        $this->assertDatabaseCount('trip_events', 1);
        $this->record($reservation, 'arrivee_retour', ['actual_nb_children' => 9, 'actual_nb_adults' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_first_departure_opens_with_the_configured_window_and_closes_at_the_end_of_the_day(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->driverUser());

        Carbon::setTestNow('2026-06-16 05:55:00'); // 07:55 : plus d'une heure avant 09:00
        $this->record($reservation, 'depart_aller')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson($this->url($reservation, 'trip-progress'))
            ->assertOk()
            ->assertJsonPath('meta.first_departure_opens_at', '2026-06-16T08:00:00')
            ->assertJsonPath('meta.can_record', false);

        config(['driver_journey.departure_window_minutes' => 120]); // réglage plus large : 07:00
        $this->getJson($this->url($reservation, 'trip-progress'))->assertJsonPath('meta.can_record', true);
        $this->record($reservation, 'depart_aller')->assertCreated();

        $late = $this->confirmedReservation(['departure_time' => '14:00:00', 'return_time' => '16:00:00']);
        Carbon::setTestNow('2026-06-16 22:30:00'); // 00:30 le 17/06 à Bruxelles : jour du trajet passé
        $this->record($late, 'depart_aller')->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_agent_records_without_time_limit_and_access_is_limited(): void
    {
        $reservation = $this->confirmedReservation();
        Carbon::setTestNow('2026-06-20 10:00:00'); // bien après le trajet

        Sanctum::actingAs($this->otherDriverUser());
        $this->record($reservation, 'depart_aller')->assertForbidden();
        $this->getJson($this->url($reservation, 'trip-progress'))->assertForbidden();

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $this->record($reservation, 'depart_aller')->assertForbidden();

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->record($reservation, 'depart_aller')
            ->assertCreated()
            ->assertJsonPath('data.source', 'agent')
            ->assertJsonPath('data.latitude', 50.2591);

        $cancelled = $this->confirmedReservation(['status' => ReservationStatus::ANNULE, 'departure_time' => '13:00:00', 'return_time' => '15:00:00']);
        $this->record($cancelled, 'depart_aller')->assertUnprocessable()->assertJsonValidationErrors('reservation');
    }

    public function test_a_trip_declared_not_realised_cannot_be_followed(): void
    {
        $reservation = $this->confirmedReservation();
        TripReport::query()->create([
            'reservation_id' => $reservation->id,
            'driver_id' => $reservation->driver_id,
            'outcome' => TripReportOutcome::NON_REALISE,
            'remarks' => 'Groupe absent.',
            'reported_by_user_id' => $this->driverUser()->id,
            'reported_at' => now(),
        ]);
        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-16 07:00:00');

        $this->record($reservation, 'depart_aller')->assertUnprocessable()->assertJsonValidationErrors('reservation');
    }

    public function test_position_state_is_kept_and_coordinates_are_shown_to_agents_only(): void
    {
        $reservation = $this->confirmedReservation();
        $other = $this->confirmedReservation(['driver_id' => $this->otherDriver()->id, 'departure_time' => '13:00:00', 'return_time' => '15:00:00']);
        Carbon::setTestNow('2026-06-16 07:00:00');

        Sanctum::actingAs($this->driverUser());
        $this->postJson($this->url($reservation, 'trip-events'), ['type' => 'depart_aller', 'position' => self::NO_POSITION])
            ->assertCreated()
            ->assertJsonPath('data.position_state', 'refusee')
            ->assertJsonMissingPath('data.latitude');

        // « envoyée » sans coordonnées : 422 ; le chauffeur ne voit pas l'avancement d'un trajet d'un autre
        $this->postJson($this->url($reservation, 'trip-events'), ['type' => 'arrivee_aller', 'position' => ['state' => 'envoyee']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('position.latitude');

        $ids = collect($this->getJson("/api/trip-progress?reservation_ids[]={$reservation->id}&reservation_ids[]={$other->id}")
            ->assertOk()
            ->json('data'))->pluck('reservation_id');
        $this->assertTrue($ids->contains($reservation->id));
        $this->assertFalse($ids->contains($other->id));

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->getJson("/api/trip-progress?reservation_ids[]={$reservation->id}&reservation_ids[]={$other->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_another_driver_learns_nothing_about_a_trip_that_is_not_his(): void
    {
        // Trajet d'un autre chauffeur, pas encore confirmé : 403 (droit), pas 422 (statut)
        $reservation = $this->confirmedReservation(['driver_id' => $this->otherDriver()->id, 'status' => ReservationStatus::APPROUVE]);
        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-16 07:00:00');

        $this->record($reservation, 'depart_aller')->assertForbidden();
    }

    public function test_retrying_the_last_arrival_creates_the_report_that_could_not_be_created(): void
    {
        $reservation = $this->confirmedReservation();
        // Les quatre étapes sont enregistrées, mais le compte rendu n'a pas pu être créé à la dernière arrivée
        foreach (['depart_aller' => '07:02', 'arrivee_aller' => '07:25', 'depart_retour' => '09:05', 'arrivee_retour' => '09:30'] as $type => $utc) {
            TripEvent::query()->create([
                'reservation_id' => $reservation->id,
                'driver_id' => $reservation->driver_id,
                'type' => $type,
                'occurred_at' => "2026-06-16 {$utc}:00",
                'source' => TripEventSource::CHAUFFEUR,
                'position_state' => 'refusee',
            ]);
        }
        $this->assertDatabaseCount('trip_reports', 0);

        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-16 09:31:00');
        $this->record($reservation, 'arrivee_retour', ['actual_nb_children' => 9, 'actual_nb_adults' => 1])
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('meta.trip_report.outcome', 'realise')
            ->assertJsonPath('meta.trip_report.actual_departure_time', '09:02')
            ->assertJsonPath('meta.trip_report.actual_nb_children', 9);

        // Un nouvel appui ne recrée rien
        $this->record($reservation, 'arrivee_retour', ['actual_nb_children' => 9, 'actual_nb_adults' => 1])
            ->assertOk()
            ->assertJsonPath('meta.trip_report', null);
        $this->assertDatabaseCount('trip_reports', 1);
    }

    private function record(Reservation $reservation, string $type, array $extra = [])
    {
        return $this->postJson($this->url($reservation, 'trip-events'), ['type' => $type, 'position' => self::POSITION, ...$extra]);
    }

    private function url(Reservation $reservation, string $path): string
    {
        return "/api/reservations/{$reservation->id}/{$path}";
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

    private function otherDriverUser(): User
    {
        return $this->otherDriver()->user;
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
