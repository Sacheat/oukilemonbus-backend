<?php

namespace Tests\Feature;

use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * POST /api/reservations/check-trip : vérification du trajet seul, sans passagers ni motif.
 */
class ReservationCheckTripTest extends TestCase
{
    use DatabaseTransactions;

    /** Distance et durée renvoyées par le faux OpenRouteService (modifiables par test). */
    private int $orsMeters = 12000;

    private int $orsSeconds = 1800;

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

        // Lundi 20 avril 2026 : le lundi 11 mai respecte le préavis de 15 jours.
        Carbon::setTestNow('2026-04-20 09:00:00');

        config([
            'services.openrouteservice.key' => 'test-key',
            'services.openrouteservice.base_url' => 'https://ors.test',
            'reservations.closed_dates' => ['2026-01-01'],
        ]);

        Http::fake([
            'https://ors.test/*' => fn () => Http::response([
                'routes' => [['summary' => ['distance' => $this->orsMeters, 'duration' => $this->orsSeconds]]],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_school_trip_is_allowed_with_automatic_approval_without_passengers_or_purpose(): void
    {
        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));

        $this->postJson('/api/reservations/check-trip', $this->payload('Ecole communale de Dinant'))
            ->assertOk()
            ->assertJsonPath('data.is_allowed', true)
            ->assertJsonPath('data.approval_type', 'auto')
            ->assertJsonPath('data.status', ReservationStatus::APPROUVE->value)
            ->assertJsonPath('data.distance_km', 12)
            ->assertJsonStructure(['data' => [
                'is_allowed', 'status', 'approval_type', 'distance_km', 'estimated_duration_min',
                'duration_margin_min', 'estimated_occupation', 'availability', 'conflicts', 'warnings',
            ]]);
    }

    public function test_asbl_trip_outside_allowed_hours_returns_derogation_warnings(): void
    {
        Sanctum::actingAs($this->seeded('responsable.test@bus-communal.local'));

        $response = $this->postJson('/api/reservations/check-trip', $this->payload('ASBL Les Amis du Parc', [
            'departure_time' => '07:00',
            'return_time' => '17:30',
        ]))->assertOk()
            ->assertJsonPath('data.is_allowed', true)
            ->assertJsonPath('data.approval_type', 'manuel');

        $types = collect($response->json('data.warnings'))->pluck('type')->all();
        $this->assertContains(ReservationConflictType::DEPARTURE_BEFORE_ALLOWED_TIME->value, $types);
        $this->assertContains(ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value, $types);
    }

    public function test_all_blocking_rules_are_returned_together(): void
    {
        $this->orsMeters = 60000;
        $this->orsSeconds = 3600;
        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));

        // Samedi 25 avril : week-end ET préavis insuffisant ; 60 km : distance dépassée.
        $response = $this->postJson('/api/reservations/check-trip', $this->payload('Ecole communale de Dinant', [
            'trip_date' => '2026-04-25',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['trip_date', 'destination_location_id']);

        $this->assertSame([
            'La demande doit être introduite au moins 15 jours avant la date souhaitée.',
            'Les réservations ne sont pas autorisées le week-end.',
        ], $response->json('errors.trip_date'));
        $this->assertSame(
            ['La distance entre le lieu de départ et la destination dépasse 50 km.'],
            $response->json('errors.destination_location_id')
        );
    }

    public function test_unreachable_routing_service_falls_back_to_straight_line_estimate(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));

        $distance = $this->postJson('/api/reservations/check-trip', $this->payload('Ecole communale de Dinant'))
            ->assertOk()
            ->json('data.distance_km');

        $this->assertIsNumeric($distance);
    }

    public function test_same_pickup_and_destination_is_rejected(): void
    {
        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));
        $pickup = Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail();

        $this->postJson('/api/reservations/check-trip', $this->payload('Ecole communale de Dinant', [
            'destination_location_id' => $pickup->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('destination_location_id');
    }

    public function test_user_cannot_check_a_trip_for_an_organization_he_does_not_belong_to(): void
    {
        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));

        $this->postJson('/api/reservations/check-trip', $this->payload('ASBL Les Amis du Parc'))
            ->assertForbidden();
    }

    public function test_passengers_and_purpose_are_not_required(): void
    {
        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));

        $response = $this->postJson('/api/reservations/check-trip', [])->assertUnprocessable();

        $this->assertEqualsCanonicalizing(
            ['organization_id', 'pickup_location_id', 'destination_location_id', 'trip_date', 'departure_time', 'return_time'],
            array_keys($response->json('errors'))
        );
    }

    public function test_checking_a_trip_creates_or_changes_nothing(): void
    {
        $tables = ['reservations', 'reservation_conflicts', 'reservation_status_history', 'domain_events', 'notifications'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $organization = Organization::query()->where('name', 'ASBL Les Amis du Parc')->firstOrFail();

        Sanctum::actingAs($this->seeded('responsable.test@bus-communal.local'));
        $this->postJson('/api/reservations/check-trip', $this->payload('ASBL Les Amis du Parc'))->assertOk();

        $after = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $this->assertSame($before->all(), $after->all());
        $this->assertSame(
            $organization->reservations_used_this_year,
            $organization->fresh()->reservations_used_this_year
        );
    }

    public function test_check_trip_exposes_estimated_arrival_times(): void
    {
        $this->orsSeconds = 1080;
        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));

        $this->postJson('/api/reservations/check-trip', $this->payload('Ecole communale de Dinant', [
            'departure_time' => '10:00',
            'return_time' => '13:00',
        ]))
            ->assertOk()
            ->assertJsonPath('data.estimated_duration_min', 18)
            ->assertJsonPath('data.estimated_occupation.outbound_arrival_at', '2026-05-11T10:18:00')
            ->assertJsonPath('data.estimated_occupation.return_arrival_at', '2026-05-11T13:18:00');
    }

    private function payload(string $organizationName, array $overrides = []): array
    {
        $organization = Organization::query()->where('name', $organizationName)->firstOrFail();
        $pickup = Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail();
        $destination = Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail();

        return array_merge([
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-05-11',
            'departure_time' => '09:00',
            'return_time' => '11:00',
        ], $overrides);
    }

    private function seeded(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
