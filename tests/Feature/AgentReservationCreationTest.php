<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Organization;
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
 * Réservation et série encodées par un agent ou un admin (demande téléphonée) : pour n'importe quelle organisation,
 * au nom d'un de ses membres, et sans le préavis de 15 jours (signalé dans l'historique). L'usager garde le préavis.
 */
class AgentReservationCreationTest extends TestCase
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
        Carbon::setTestNow('2026-04-20 09:00:00');
        config([
            'services.openrouteservice.key' => 'test-key',
            'services.openrouteservice.base_url' => 'https://ors.test',
            'reservations.closed_dates' => [],
        ]);
        Http::fake(['https://ors.test/*' => Http::response(['routes' => [['summary' => ['distance' => 12000, 'duration' => 1800]]]])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_agent_books_four_days_ahead_for_a_member_and_the_history_says_the_notice_was_waived(): void
    {
        $member = $this->user('user.test@bus-communal.local');
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        // Vendredi 24 avril : 4 jours, sous le préavis de 15 jours
        $this->postJson('/api/reservations/preview', $this->payload('2026-04-24'))->assertOk();
        $id = $this->postJson('/api/reservations', [...$this->payload('2026-04-24'), 'requested_by_user_id' => $member->id])
            ->assertCreated()
            ->json('data.id');

        $reservation = Reservation::findOrFail($id);
        $this->assertSame($member->id, $reservation->requested_by_user_id);
        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $id,
            'reason' => 'Création de la demande par un agent (préavis de 15 jours non appliqué)',
        ]);
    }

    public function test_the_requester_keeps_the_notice_and_must_be_a_member(): void
    {
        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $this->postJson('/api/reservations', $this->payload('2026-04-24'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trip_date');

        // Agent : un demandeur qui n'est pas membre de l'organisation est refusé
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $stranger = User::factory()->create(['active' => true]);
        $this->postJson('/api/reservations', [...$this->payload('2026-06-16'), 'requested_by_user_id' => $stranger->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.requested_by_user_id.0', 'Le demandeur doit être un membre actif de l’organisation choisie.');

        // Sans demandeur : l'agent lui-même, et l'historique le dit
        $id = $this->postJson('/api/reservations', $this->payload('2026-06-16'))->assertCreated()->json('data.id');
        $this->assertSame($this->user('agent.test@bus-communal.local')->id, Reservation::findOrFail($id)->requested_by_user_id);
        $this->assertDatabaseHas('reservation_status_history', ['reservation_id' => $id, 'reason' => 'Création de la demande par un agent']);
    }

    public function test_an_agent_series_keeps_the_dates_inside_the_notice(): void
    {
        $series = [
            ...$this->payload(null),
            'frequency' => 'hebdomadaire',
            'days_of_week' => [1],
            'start_date' => '2026-04-27',
            'end_date' => '2026-05-11',
        ];

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));
        $skipped = $this->postJson('/api/recurrences/preview', $series)->assertOk()->json('data.skipped_dates');
        $this->assertSame(['2026-04-27', '2026-05-04'], array_column($skipped, 'date'));

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson('/api/recurrences/preview', $series)
            ->assertOk()
            ->assertJsonPath('data.valid_dates', ['2026-04-27', '2026-05-04', '2026-05-11'])
            ->assertJsonPath('data.total_skipped', 0);
        $this->postJson('/api/recurrences', [...$series, 'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id])
            ->assertCreated()
            ->assertJsonPath('meta.created_count', 3);
    }

    private function payload(?string $tripDate): array
    {
        return array_filter([
            'organization_id' => Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'trip_date' => $tripDate,
            'departure_time' => '09:00',
            'return_time' => '11:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie téléphonée',
        ], fn ($value) => $value !== null);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
