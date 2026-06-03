<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Recurrence;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dates de référence (Carbon::setTestNow = 2026-04-20) :
 *   - délai minimum 15 jours → première date valide = 2026-05-05
 *   - start_date  = 2026-05-11 (lundi, 21 jours)
 *   - end_date    = 2026-05-25 (lundi)
 *   - hebdo lundi → May 11, May 18, May 25 = 3 occurrences
 *   - bihebdo lundi → May 11 (semaine 0), May 25 (semaine 2) = 2 occurrences
 */
class RecurrenceApiTest extends TestCase
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

        Carbon::setTestNow('2026-04-20 09:00:00');

        config([
            'services.openrouteservice.key'      => 'test-key',
            'services.openrouteservice.base_url' => 'https://ors.test',
            'reservations.closed_dates'          => [],
            'reservations.min_notice_days'       => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_agent_sees_all_recurrences(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($user);
        $this->postJson('/api/recurrences', $this->weeklyPayload($school))->assertCreated();

        Sanctum::actingAs($agent);
        $this->getJson('/api/recurrences')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_only_sees_own_org_recurrences(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $owner = $this->seededUser('user.test@bus-communal.local');
        $stranger = User::factory()->create();
        $stranger->assignRole('user');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($owner);
        $this->postJson('/api/recurrences', $this->weeklyPayload($school))->assertCreated();

        Sanctum::actingAs($stranger);
        $this->getJson('/api/recurrences')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_can_filter_by_active_status(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($user);
        $recurrenceId = $this->postJson('/api/recurrences', $this->weeklyPayload($school))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($agent);
        $this->patchJson("/api/recurrences/{$recurrenceId}/cancel", ['reason' => 'Test.']);

        $this->getJson('/api/recurrences?is_active=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/recurrences?is_active=0')->assertOk()->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------------------------
    // Preview
    // -------------------------------------------------------------------------

    public function test_preview_response_flags_conflicts_as_approximate(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $this->postJson('/api/recurrences/preview', $this->weeklyPayload($school))
            ->assertOk()
            ->assertJsonPath('data.conflicts_are_approximate', true);
    }

    public function test_school_user_can_preview_weekly_recurrence(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $this->postJson('/api/recurrences/preview', $this->weeklyPayload($school))
            ->assertOk()
            ->assertJsonPath('data.total_valid_occurrences', 3)
            ->assertJsonPath('data.total_skipped', 0)
            ->assertJsonPath('data.valid_dates.0', '2026-05-11')
            ->assertJsonPath('data.valid_dates.1', '2026-05-18')
            ->assertJsonPath('data.valid_dates.2', '2026-05-25');
    }

    public function test_preview_skips_date_below_minimum_notice(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        // May 4 = 14 days from now → below minimum notice of 15 days
        $this->postJson('/api/recurrences/preview', array_merge(
            $this->weeklyPayload($school),
            ['start_date' => '2026-05-04', 'end_date' => '2026-05-25']
        ))
            ->assertOk()
            ->assertJsonPath('data.total_valid_occurrences', 3)
            ->assertJsonPath('data.total_skipped', 1)
            ->assertJsonPath('data.skipped_dates.0.date', '2026-05-04');
    }

    public function test_preview_skips_closed_dates(): void
    {
        config(['reservations.closed_dates' => ['2026-05-18']]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $this->postJson('/api/recurrences/preview', $this->weeklyPayload($school))
            ->assertOk()
            ->assertJsonPath('data.total_valid_occurrences', 2)
            ->assertJsonPath('data.total_skipped', 1)
            ->assertJsonPath('data.skipped_dates.0.date', '2026-05-18')
            ->assertJsonPath('data.skipped_dates.0.reason', 'Jour férié ou date de fermeture');
    }

    public function test_non_school_organization_is_rejected(): void
    {
        $user = $this->seededUser('agent.test@bus-communal.local');
        $asbl = $this->seededOrg('ASBL Les Amis du Parc');
        Sanctum::actingAs($user);

        $this->postJson('/api/recurrences/preview', $this->weeklyPayload($asbl))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');
    }

    public function test_user_not_attached_to_school_cannot_preview(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $this->postJson('/api/recurrences/preview', $this->weeklyPayload($school))
            ->assertForbidden();
    }

    public function test_chauffeur_cannot_create_recurrence(): void
    {
        $chauffeur = $this->seededUser('chauffeur.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($chauffeur);

        $this->postJson('/api/recurrences', $this->weeklyPayload($school))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function test_school_user_can_create_weekly_recurrence(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recurrences', $this->weeklyPayload($school));

        $response->assertCreated()
            ->assertJsonPath('data.frequency', 'hebdomadaire')
            ->assertJsonPath('data.occurrence_count', 3)
            ->assertJsonPath('data.is_active', true);

        $recurrenceId = $response->json('data.id');

        $this->assertDatabaseHas('recurrences', [
            'id'               => $recurrenceId,
            'frequency'        => 'hebdomadaire',
            'occurrence_count' => 3,
            'is_active'        => true,
        ]);

        $this->assertDatabaseCount('reservations', 3);

        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-11', 'recurrence_id' => $recurrenceId]);
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-18', 'recurrence_id' => $recurrenceId]);
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-25', 'recurrence_id' => $recurrenceId]);
    }

    public function test_all_school_recurrence_occurrences_are_auto_confirmed(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $this->postJson('/api/recurrences', $this->weeklyPayload($school))->assertCreated();

        $statuses = Reservation::query()->pluck('status')->map(fn ($s) => $s instanceof ReservationStatus ? $s->value : $s)->all();
        $this->assertCount(3, $statuses);
        foreach ($statuses as $status) {
            $this->assertSame(ReservationStatus::CONFIRME->value, $status);
        }
    }

    public function test_biweekly_recurrence_generates_alternate_weeks_only(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recurrences', array_merge(
            $this->weeklyPayload($school),
            ['frequency' => 'bihebdomadaire']
        ));

        $response->assertCreated()
            ->assertJsonPath('data.occurrence_count', 2);

        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-11']);
        $this->assertDatabaseMissing('reservations', ['trip_date' => '2026-05-18']);
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-25']);
    }

    public function test_monthly_recurrence_skips_weekend_occurrences(): void
    {
        // start = 2026-05-11 (lundi) → mensuel : 11 mai, 11 juin (jeu), 11 juil (sam → SKIP)
        // Avec end_date = 2026-07-20, 2 dates valides, 1 sautée (week-end)
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recurrences', array_merge(
            $this->weeklyPayload($school),
            ['frequency' => 'mensuel', 'days_of_week' => null, 'end_date' => '2026-07-20']
        ));

        $response->assertCreated()
            ->assertJsonPath('data.occurrence_count', 2);

        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-11']);
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-06-11']);
        $this->assertDatabaseMissing('reservations', ['trip_date' => '2026-07-11']);
    }

    public function test_weekly_recurrence_with_multiple_days_of_week(): void
    {
        // Lundi [1] + Mercredi [3] du 11 au 20 mai → 11, 13, 18, 20 mai = 4 occurrences
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recurrences', array_merge(
            $this->weeklyPayload($school),
            ['days_of_week' => [1, 3], 'end_date' => '2026-05-20']
        ));

        $response->assertCreated()
            ->assertJsonPath('data.occurrence_count', 4);

        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-11']); // lundi
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-13']); // mercredi
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-18']); // lundi
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-20']); // mercredi
        $this->assertDatabaseMissing('reservations', ['trip_date' => '2026-05-12']); // mardi absent
        $this->assertDatabaseMissing('reservations', ['trip_date' => '2026-05-15']); // vendredi absent
    }

    public function test_occurrence_count_matches_reservations_actually_created(): void
    {
        // La 2e occurrence retourne 70 km (> max 50 km) → ValidationException attrapée.
        // occurrence_count doit valoir 2 (réellement créées) et non 3 (prévues).
        Http::fake([
            'https://ors.test/*' => Http::sequence()
                ->push(['routes' => [['summary' => ['distance' => 12000, 'duration' => 1800]]]]) // May 11 → OK
                ->push(['routes' => [['summary' => ['distance' => 70000, 'duration' => 5000]]]]) // May 18 → >50km, FAIL
                ->push(['routes' => [['summary' => ['distance' => 12000, 'duration' => 1800]]]]), // May 25 → OK
        ]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recurrences', $this->weeklyPayload($school));

        $response->assertCreated();

        $recurrenceId = $response->json('data.id');

        // occurrence_count reflète les réservations réelles, pas le preview
        $this->assertDatabaseHas('recurrences', [
            'id'               => $recurrenceId,
            'occurrence_count' => 2,
        ]);

        // La DB contient exactement 2 réservations
        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-11', 'recurrence_id' => $recurrenceId]);
        $this->assertDatabaseMissing('reservations', ['trip_date' => '2026-05-18', 'recurrence_id' => $recurrenceId]);
        $this->assertDatabaseHas('reservations', ['trip_date' => '2026-05-25', 'recurrence_id' => $recurrenceId]);

        // occurrence_count == count réel en DB — c'est la garantie d'intégrité
        $actualCount = \App\Models\Reservation::query()->where('recurrence_id', $recurrenceId)->count();
        $storedCount = \App\Models\Recurrence::query()->find($recurrenceId)->occurrence_count;
        $this->assertSame($actualCount, $storedCount);
    }

    public function test_recurrence_with_no_valid_dates_is_rejected(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');
        Sanctum::actingAs($user);

        // Only date in range is within minimum notice period
        $this->postJson('/api/recurrences', array_merge(
            $this->weeklyPayload($school),
            ['start_date' => '2026-04-27', 'end_date' => '2026-05-03']
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_user_from_other_org_cannot_view_recurrence(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $owner = $this->seededUser('user.test@bus-communal.local');
        $stranger = User::factory()->create();
        $stranger->assignRole('user');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($owner);
        $recurrenceId = $this->postJson('/api/recurrences', $this->weeklyPayload($school))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($stranger);
        $this->getJson("/api/recurrences/{$recurrenceId}")
            ->assertForbidden();
    }

    public function test_agent_can_view_recurrence_with_ordered_reservations(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($user);
        $recurrenceId = $this->postJson('/api/recurrences', $this->weeklyPayload($school))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($agent);
        $this->getJson("/api/recurrences/{$recurrenceId}")
            ->assertOk()
            ->assertJsonPath('data.id', $recurrenceId)
            ->assertJsonPath('data.occurrence_count', 3)
            ->assertJsonCount(3, 'data.reservations')
            ->assertJsonPath('data.reservations.0.trip_date', '2026-05-11')
            ->assertJsonPath('data.reservations.1.trip_date', '2026-05-18')
            ->assertJsonPath('data.reservations.2.trip_date', '2026-05-25');
    }

    // -------------------------------------------------------------------------
    // Cancel remaining
    // -------------------------------------------------------------------------

    public function test_agent_can_cancel_remaining_occurrences(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($user);
        $recurrenceId = $this->postJson('/api/recurrences', $this->weeklyPayload($school))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($agent);
        $this->patchJson("/api/recurrences/{$recurrenceId}/cancel", ['reason' => 'Annulation de la série.'])
            ->assertOk()
            ->assertJsonPath('data.cancelled_count', 3);

        $this->assertDatabaseHas('recurrences', ['id' => $recurrenceId, 'is_active' => false]);

        $statuses = Reservation::query()->pluck('status')->map(fn ($s) => $s instanceof ReservationStatus ? $s->value : $s)->all();
        foreach ($statuses as $status) {
            $this->assertSame(ReservationStatus::ANNULE->value, $status);
        }
    }

    public function test_cancel_does_not_touch_already_terminal_occurrences(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($user);
        $recurrenceId = $this->postJson('/api/recurrences', $this->weeklyPayload($school))
            ->assertCreated()
            ->json('data.id');

        // Manually cancel one occurrence before the bulk cancel
        $firstReservation = Reservation::query()
            ->where('recurrence_id', $recurrenceId)
            ->orderBy('trip_date')
            ->first();

        Sanctum::actingAs($agent);
        $this->patchJson("/api/reservations/{$firstReservation->id}/cancel", ['reason' => 'Annulation individuelle.'])
            ->assertOk();

        $this->patchJson("/api/recurrences/{$recurrenceId}/cancel", ['reason' => 'Annulation de la série.'])
            ->assertOk()
            ->assertJsonPath('data.cancelled_count', 2); // Only the 2 remaining
    }

    public function test_user_from_other_org_cannot_cancel_recurrence(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsResponse()]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $otherUser = User::factory()->create();
        $otherUser->assignRole('user');
        $school = $this->seededOrg('Ecole communale de Dinant');

        Sanctum::actingAs($user);
        $recurrenceId = $this->postJson('/api/recurrences', $this->weeklyPayload($school))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($otherUser);
        $this->patchJson("/api/recurrences/{$recurrenceId}/cancel", ['reason' => 'Tentative non autorisée.'])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function weeklyPayload(Organization $org): array
    {
        [$pickup, $destination] = $this->seededLocations();

        return [
            'organization_id'        => $org->id,
            'pickup_location_id'     => $pickup->id,
            'destination_location_id' => $destination->id,
            'departure_time'         => '09:00',
            'return_time'            => '11:00',
            'nb_children'            => 10,
            'nb_adults'              => 1,
            'trip_purpose'           => 'Sortie récurrente',
            'frequency'              => 'hebdomadaire',
            'days_of_week'           => [1],
            'start_date'             => '2026-05-11',
            'end_date'               => '2026-05-25',
        ];
    }

    private function orsResponse(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'routes' => [[
                'summary' => ['distance' => 12000, 'duration' => 1800],
            ]],
        ]);
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededOrg(string $name): Organization
    {
        return Organization::query()->where('name', $name)->firstOrFail();
    }

    private function seededLocations(): array
    {
        return [
            Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail(),
            Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail(),
        ];
    }
}
