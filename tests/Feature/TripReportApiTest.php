<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Enums\UnavailabilityReasonType;
use App\Models\DomainEvent;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Unavailability;
use App\Models\Vehicle;
use App\Services\Reservations\ReservationService;
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
 * Compte rendu de trajet (PUT/GET /reservations/{id}/trip-report, GET /trip-reports).
 * Trajet de test : mardi 16 juin 2026, départ 09:00 (heure de Bruxelles, UTC+2 en été).
 */
class TripReportApiTest extends TestCase
{
    use DatabaseTransactions;

    private const REALISED = [
        'outcome' => 'realise',
        'actual_departure_time' => '09:05',
        'actual_return_time' => '11:20',
        'actual_nb_children' => 9,
        'actual_nb_adults' => 1,
        'remarks' => 'Un enfant absent.',
    ];

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

    public function test_assigned_driver_can_report_a_realised_trip_and_correct_it(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-16 09:30:00'); // 11:30 à Bruxelles, après le départ

        // Première déclaration : 201 ; correction : 200
        $this->putJson($this->url($reservation), self::REALISED)
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'realise')
            ->assertJsonPath('data.outcome_label', 'Réalisé')
            ->assertJsonPath('data.actual_departure_time', '09:05')
            ->assertJsonPath('data.actual_nb_children', 9)
            ->assertJsonPath('data.reported_by_driver', true);

        $this->putJson($this->url($reservation), [...self::REALISED, 'actual_nb_children' => 8])
            ->assertOk()
            ->assertJsonPath('data.actual_nb_children', 8);

        $this->assertDatabaseCount('trip_reports', 1);
        $this->assertSame(ReservationStatus::CONFIRME, $reservation->refresh()->status);
    }

    public function test_driver_cannot_report_before_departure_nor_after_next_day_noon(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->driverUser());

        Carbon::setTestNow('2026-06-16 06:30:00'); // 08:30 à Bruxelles, avant 09:00
        $this->putJson($this->url($reservation), self::REALISED)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        Carbon::setTestNow('2026-06-17 09:30:00'); // 11:30 le 17/06 à Bruxelles : délai de grâce, encore ouvert
        $this->putJson($this->url($reservation), self::REALISED)->assertCreated();

        Carbon::setTestNow('2026-06-17 10:30:00'); // 12:30 le 17/06 à Bruxelles : délai dépassé
        $this->putJson($this->url($reservation), self::REALISED)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
    }

    public function test_agent_can_report_after_departure_without_time_limit_but_not_before(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        Carbon::setTestNow('2026-06-16 06:30:00'); // 08:30 à Bruxelles, avant 09:00
        $this->getJson($this->url($reservation))->assertOk()->assertJsonPath('meta.can_submit', false);
        $this->putJson($this->url($reservation), self::REALISED)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        Carbon::setTestNow('2026-09-01 10:00:00'); // bien après le délai du chauffeur
        $this->getJson($this->url($reservation))->assertOk()->assertJsonPath('meta.can_submit', true);
        $this->putJson($this->url($reservation), self::REALISED)
            ->assertCreated()
            ->assertJsonPath('data.reported_by_name', 'Bruno Agent')
            ->assertJsonPath('data.reported_by_driver', false);
    }

    public function test_another_driver_cannot_see_or_report_the_trip(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->otherDriverUser());
        Carbon::setTestNow('2026-06-16 09:30:00');

        $this->getJson($this->url($reservation))->assertForbidden();
        $this->putJson($this->url($reservation), self::REALISED)->assertForbidden();
    }

    public function test_not_realised_requires_a_reason_and_notifies_internal_users(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-16 09:30:00');

        $this->putJson($this->url($reservation), ['outcome' => 'non_realise'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');

        $this->putJson($this->url($reservation), ['outcome' => 'non_realise', 'remarks' => 'Groupe absent au rendez-vous.'])
            ->assertCreated()
            ->assertJsonPath('data.actual_departure_time', null);

        foreach (['agent.test@bus-communal.local', 'admin.test@bus-communal.local'] as $email) {
            $this->assertDatabaseHas('notification_deliveries', [
                'notifiable_id' => $this->user($email)->id,
                'event_key' => 'trip_report.not_realised',
            ]);
        }
    }

    public function test_agent_is_not_notified_of_own_report_and_history_keeps_hh_mm_times(): void
    {
        $reservation = $this->confirmedReservation();
        $agent = $this->user('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);
        Carbon::setTestNow('2026-06-16 09:30:00');

        $this->putJson($this->url($reservation), self::REALISED)->assertCreated();
        $this->putJson($this->url($reservation), ['outcome' => 'non_realise', 'remarks' => 'Bus en panne.'])->assertOk();

        // L'admin est prévenu, pas l'agent qui a saisi
        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $this->user('admin.test@bus-communal.local')->id,
            'event_key' => 'trip_report.not_realised',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notifiable_id' => $agent->id,
            'event_key' => 'trip_report.not_realised',
        ]);

        // Anciennes valeurs relues en base, au même format que la saisie (HH:MM)
        $event = DomainEvent::query()
            ->where('aggregate_id', $reservation->id)
            ->where('event_type', 'trip_report.updated')
            ->firstOrFail();
        $this->assertSame(self::REALISED['actual_departure_time'], $event->old_values['actual_departure_time']);
        $this->assertNull($event->new_values['actual_departure_time']);
    }

    public function test_a_report_locks_the_reservation_against_changes_reassignment_and_cancellation(): void
    {
        $reservation = $this->confirmedReservation();
        $agent = $this->user('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);
        Carbon::setTestNow('2026-06-16 09:30:00');

        $this->putJson($this->url($reservation), self::REALISED)->assertCreated();

        $locked = fn ($response) => $response->assertUnprocessable()
            ->assertJsonPath('errors.reservation.0', fn (string $message): bool => str_contains($message, 'compte rendu'));

        $locked($this->patchJson("/api/reservations/{$reservation->id}", ['trip_date' => '2026-07-01']));
        $locked($this->patchJson("/api/reservations/{$reservation->id}/assign", ['driver_id' => $this->otherDriver()->id]));
        $locked($this->patchJson("/api/reservations/{$reservation->id}/confirm-passengers", ['nb_adults' => 2]));
        $locked($this->patchJson("/api/reservations/{$reservation->id}/cancel", ['reason' => 'Nettoyage']));

        // Indisponibilité saisie après coup : le trajet déclaré n'est ni mis en conflit ni désaffecté
        $unavailability = Unavailability::create([
            'reason_type' => UnavailabilityReasonType::DRIVER,
            'reason' => 'Maladie',
            'driver_id' => $reservation->driver_id,
            'start_datetime' => '2026-06-16 08:00:00',
            'end_datetime' => '2026-06-16 18:00:00',
            'created_by_user_id' => $agent->id,
            'is_active' => true,
        ]);
        $this->assertSame(0, app(ReservationService::class)->applyUnavailabilityImpact($unavailability, $agent));

        $reservation->refresh();
        $this->assertSame('2026-06-16', $reservation->trip_date->toDateString());
        $this->assertSame(ReservationStatus::CONFIRME, $reservation->status);
        $this->assertNotNull($reservation->driver_id);
    }

    public function test_a_not_realised_trip_is_not_locked_against_cancellation(): void
    {
        $reservation = $this->confirmedReservation();
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        Carbon::setTestNow('2026-06-16 09:30:00');

        $this->putJson($this->url($reservation), ['outcome' => 'non_realise', 'remarks' => 'Groupe absent.'])->assertCreated();

        // Seul le délai d'annulation (veille du trajet) s'applique encore, pas le verrou du compte rendu
        $this->patchJson("/api/reservations/{$reservation->id}/cancel", ['reason' => 'Trajet non réalisé'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reservation.0', fn (string $message): bool => ! str_contains($message, 'compte rendu'));
    }

    public function test_only_confirmed_trips_can_have_a_report(): void
    {
        $reservation = $this->confirmedReservation(['status' => ReservationStatus::ANNULE]);
        Sanctum::actingAs($this->driverUser());
        Carbon::setTestNow('2026-06-16 09:30:00');

        $this->putJson($this->url($reservation), self::REALISED)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
    }

    public function test_show_returns_the_driver_window_and_index_is_limited_to_own_trips(): void
    {
        $reservation = $this->confirmedReservation();
        $otherReservation = $this->confirmedReservation(['driver_id' => $this->otherDriver()->id, 'departure_time' => '13:00:00', 'return_time' => '15:00:00']);
        Carbon::setTestNow('2026-06-16 09:30:00');

        // L'agent déclare le trajet de l'autre chauffeur (départ 13:00), une fois commencé
        Carbon::setTestNow('2026-06-16 11:30:00'); // 13:30 à Bruxelles
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->putJson($this->url($otherReservation), self::REALISED)->assertCreated();

        Sanctum::actingAs($this->driverUser());
        $this->getJson($this->url($reservation))
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.driver_window_opens_at', '2026-06-16T09:00:00')
            ->assertJsonPath('meta.driver_window_closes_at', '2026-06-17T12:00:00')
            ->assertJsonPath('meta.can_submit', true);

        $this->putJson($this->url($reservation), self::REALISED)->assertCreated();

        $ids = collect($this->getJson("/api/trip-reports?reservation_ids[]={$reservation->id}&reservation_ids[]={$otherReservation->id}")
            ->assertOk()
            ->json('data'))->pluck('reservation_id');

        $this->assertTrue($ids->contains($reservation->id));
        $this->assertFalse($ids->contains($otherReservation->id));
    }

    private function url(Reservation $reservation): string
    {
        return "/api/reservations/{$reservation->id}/trip-report";
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
