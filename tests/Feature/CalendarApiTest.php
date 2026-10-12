<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Municipality;
use App\Models\MunicipalityCalendarPeriod;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calendar\ClosedDaysService;
use App\Services\Reservations\ReservationPreviewService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Calendrier : jours fériés légaux belges calculés pour toute année, fermetures de la commune (aucune réservation)
 * et vacances scolaires (sautées par les séries, pas par une réservation ponctuelle).
 */
class CalendarApiTest extends TestCase
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
        Carbon::setTestNow('2026-10-12 09:00:00');
        config(['reservations.closed_dates' => [], 'reservations.public_holidays' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_belgian_public_holidays_are_computed_for_any_year(): void
    {
        $service = app(ClosedDaysService::class);

        // Pâques : 5 avril 2026, 28 mars 2027, 16 avril 2028
        $this->assertSame('Lundi de Pâques', $service->publicHolidays(2026)['2026-04-06']);
        $this->assertSame('Ascension', $service->publicHolidays(2026)['2026-05-14']);
        $this->assertSame('Lundi de Pentecôte', $service->publicHolidays(2026)['2026-05-25']);
        $this->assertSame('Lundi de Pâques', $service->publicHolidays(2027)['2027-03-29']);
        $this->assertSame('Ascension', $service->publicHolidays(2027)['2027-05-06']);
        $this->assertSame('Lundi de Pentecôte', $service->publicHolidays(2028)['2028-06-05']);
        $this->assertCount(10, $service->publicHolidays(2031));
    }

    public function test_a_single_reservation_is_refused_on_a_holiday_or_a_closure_but_not_during_school_holidays(): void
    {
        $municipality = $this->activeMunicipality();
        $municipality->calendarPeriods()->create(['kind' => 'fermeture', 'label' => 'Fête de Wallonie', 'start_date' => '2027-09-20', 'end_date' => '2027-09-20']);
        $municipality->calendarPeriods()->create(['kind' => 'vacances_scolaires', 'label' => 'Congé d’automne', 'start_date' => '2027-10-25', 'end_date' => '2027-11-05']);
        $preview = app(ReservationPreviewService::class);

        $this->assertRefused(fn () => $preview->ensureBookableDate('2027-01-01'), 'jour férié : Jour de l’An');
        $this->assertRefused(fn () => $preview->ensureBookableDate('2027-05-06'), 'jour férié : Ascension');
        $this->assertRefused(fn () => $preview->ensureBookableDate('2027-09-20'), 'fermeture communale : Fête de Wallonie');

        // Jour ouvrable ordinaire et vacances scolaires : une réservation ponctuelle reste possible
        $preview->ensureBookableDate('2027-09-21');
        $preview->ensureBookableDate('2027-10-27');
        $this->addToAssertionCount(2);
    }

    public function test_a_series_skips_holidays_closures_and_school_holidays_with_their_reason(): void
    {
        $municipality = $this->activeMunicipality();
        $municipality->calendarPeriods()->create(['kind' => 'vacances_scolaires', 'label' => 'Congé de détente', 'start_date' => '2027-02-15', 'end_date' => '2027-02-26']);
        $municipality->calendarPeriods()->create(['kind' => 'fermeture', 'label' => 'Pont communal', 'start_date' => '2027-03-01', 'end_date' => '2027-03-01']);
        Sanctum::actingAs(User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail());

        // Tous les lundis du 8 février au 5 avril 2027
        $response = $this->postJson('/api/recurrences/preview', $this->mondaysPayload('2027-02-08', '2027-04-05'))->assertOk();

        $skipped = collect($response->json('data.skipped_dates'))->pluck('reason', 'date')->all();
        $this->assertSame([
            '2027-02-15' => 'Vacances scolaires : Congé de détente',
            '2027-02-22' => 'Vacances scolaires : Congé de détente',
            '2027-03-01' => 'Fermeture communale : Pont communal',
            '2027-03-29' => 'Jour férié : Lundi de Pâques',
        ], $skipped);
        $this->assertSame(['2027-02-08', '2027-03-08', '2027-03-15', '2027-03-22', '2027-04-05'], $response->json('data.valid_dates'));
    }

    public function test_an_agent_manages_the_calendar_and_reads_the_computed_holidays(): void
    {
        $municipality = $this->activeMunicipality();
        Sanctum::actingAs(User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail());

        $id = $this->postJson("/api/municipalities/{$municipality->id}/calendar-periods", [
            'kind' => 'vacances_scolaires', 'label' => 'Vacances d’hiver', 'start_date' => '2027-12-20', 'end_date' => '2028-01-01',
        ])->assertCreated()->assertJsonPath('data.kind_label', 'Vacances scolaires')->json('data.id');

        // La liste d'une année reprend les périodes qui la touchent, et les fériés calculés de l'année
        $this->getJson("/api/municipalities/{$municipality->id}/calendar-periods?year=2028")
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('meta.year', 2028)
            ->assertJsonCount(10, 'meta.public_holidays')
            ->assertJsonPath('meta.public_holidays.0', ['date' => '2028-01-01', 'label' => 'Jour de l’An']);

        $this->patchJson("/api/municipality-calendar-periods/{$id}", ['end_date' => '2028-01-02'])
            ->assertOk()
            ->assertJsonPath('data.end_date', '2028-01-02');
        $this->patchJson("/api/municipality-calendar-periods/{$id}", ['end_date' => '2027-12-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
        $this->postJson("/api/municipalities/{$municipality->id}/calendar-periods", [
            'kind' => 'conge', 'label' => 'x', 'start_date' => '2027-01-01', 'end_date' => '2027-01-02',
        ])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'label']);
        // Plus de 100 jours : très probablement une faute de frappe
        $this->postJson("/api/municipalities/{$municipality->id}/calendar-periods", [
            'kind' => 'fermeture', 'label' => 'Toute l’année', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');

        // Pas de doublon : même sorte qui chevauche → refusé (création et modification) ; une fermeture le même jour, oui
        $this->postJson("/api/municipalities/{$municipality->id}/calendar-periods", [
            'kind' => 'vacances_scolaires', 'label' => 'Vacances d’hiver', 'start_date' => '2027-12-27', 'end_date' => '2027-12-31',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.start_date.0', 'Cette période chevauche « Vacances d’hiver » (du 20/12/2027 au 02/01/2028).');
        $carnival = $this->postJson("/api/municipalities/{$municipality->id}/calendar-periods", [
            'kind' => 'vacances_scolaires', 'label' => 'Congé de détente', 'start_date' => '2028-02-28', 'end_date' => '2028-03-10',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/municipality-calendar-periods/{$carnival}", ['start_date' => '2028-01-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
        $this->postJson("/api/municipalities/{$municipality->id}/calendar-periods", [
            'kind' => 'fermeture', 'label' => 'Pont', 'start_date' => '2027-12-27', 'end_date' => '2027-12-27',
        ])->assertCreated();

        $this->deleteJson("/api/municipality-calendar-periods/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('municipality_calendar_periods', ['id' => $id]);
    }

    public function test_a_plain_user_can_not_change_the_calendar(): void
    {
        $municipality = $this->activeMunicipality();
        Sanctum::actingAs(User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail());

        $this->postJson("/api/municipalities/{$municipality->id}/calendar-periods", [
            'kind' => 'fermeture', 'label' => 'Fermeture', 'start_date' => '2027-01-04', 'end_date' => '2027-01-04',
        ])->assertForbidden();
        $this->assertSame(0, MunicipalityCalendarPeriod::query()->count());
    }

    private function assertRefused(callable $check, string $reason): void
    {
        try {
            $check();
            $this->fail("Date acceptée alors qu'elle devait être refusée ({$reason}).");
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($reason, $exception->errors()['trip_date'][0]);
        }
    }

    private function activeMunicipality(): Municipality
    {
        return Municipality::query()->where('is_active', true)->orderBy('id')->first()
            ?? Municipality::create(['name' => 'Dinant', 'country' => 'BE', 'source' => 'manual', 'is_active' => true]);
    }

    private function mondaysPayload(string $start, string $end): array
    {
        return [
            'organization_id' => Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'departure_time' => '09:00',
            'return_time' => '11:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Piscine',
            'frequency' => 'hebdomadaire',
            'days_of_week' => [1],
            'start_date' => $start,
            'end_date' => $end,
        ];
    }
}
