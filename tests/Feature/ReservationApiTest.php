<?php

namespace Tests\Feature;

use App\Enums\CouncilDecisionStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReservationApiTest extends TestCase
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
            'services.openrouteservice.key' => 'test-key',
            'services.openrouteservice.base_url' => 'https://ors.test',
            'reservations.closed_dates' => ['2026-01-01'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_can_create_communal_school_reservation_and_it_is_confirmed_when_resources_are_available(): void
    {
        Http::fake([
            'https://ors.test/*' => Http::response([
                'routes' => [[
                    'summary' => [
                        'distance' => 12000,
                        'duration' => 1800,
                    ],
                ]],
            ]),
        ]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/reservations', $this->payload($organization));

        $response->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);

        $this->assertDatabaseHas('reservations', [
            'organization_id' => $organization->id,
            'requested_by_user_id' => $user->id,
            'status' => ReservationStatus::CONFIRME->value,
            'approval_type' => 'auto',
        ]);
        $this->assertDatabaseCount('reservation_conflicts', 0);
        $this->assertDatabaseCount('reservation_assignment_history', 2);
    }

    public function test_distance_over_limit_blocks_creation(): void
    {
        Http::fake([
            'https://ors.test/*' => Http::response([
                'routes' => [[
                    'summary' => [
                        'distance' => 51000,
                        'duration' => 3600,
                    ],
                ]],
            ]),
        ]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', $this->payload($organization))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination_location_id');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_schedule_overlap_creates_reservation_in_conflict(): void
    {
        Http::fake([
            'https://ors.test/*' => Http::response([
                'routes' => [[
                    'summary' => [
                        'distance' => 12000,
                        'duration' => 1800,
                    ],
                ]],
            ]),
        ]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');
        [$pickup, $destination] = $this->seededLocations();

        Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Reservation existante',
            'activity_type' => ReservationActivityType::STANDARD,
            'estimated_duration_min' => 30,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => 'auto',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', [
            ...$this->payload($organization, $pickup, $destination),
            'departure_time' => '10:00',
            'return_time' => '12:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value);

        $this->assertDatabaseHas('reservation_conflicts', [
            'type' => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved' => false,
        ]);
    }

    public function test_school_gym_reservation_is_marked_as_priority_over_standard_school_overlap(): void
    {
        Http::fake([
            'https://ors.test/*' => Http::response([
                'routes' => [[
                    'summary' => [
                        'distance' => 12000,
                        'duration' => 1800,
                    ],
                ]],
            ]),
        ]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');
        [$pickup, $destination] = $this->seededLocations();

        Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Cours standard',
            'activity_type' => ReservationActivityType::STANDARD,
            'estimated_duration_min' => 30,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => 'auto',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', [
            ...$this->payload($organization, $pickup, $destination),
            'departure_time' => '10:00',
            'return_time' => '12:00',
            'activity_type' => ReservationActivityType::GYMNASTIQUE->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->assertJsonPath('data.activity_type', ReservationActivityType::GYMNASTIQUE->value)
            ->assertJsonPath('data.conflicts.0.message', 'Le creneau chevauche une autre reservation active, mais cette demande est prioritaire selon le reglement.');
    }

    public function test_standard_school_overlap_mentions_older_priority_when_existing_gym_reservation_exists(): void
    {
        Http::fake([
            'https://ors.test/*' => Http::response([
                'routes' => [[
                    'summary' => [
                        'distance' => 12000,
                        'duration' => 1800,
                    ],
                ]],
            ]),
        ]);

        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');
        [$pickup, $destination] = $this->seededLocations();

        Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Cours de gym',
            'activity_type' => ReservationActivityType::GYMNASTIQUE,
            'estimated_duration_min' => 30,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => 'auto',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', [
            ...$this->payload($organization, $pickup, $destination),
            'departure_time' => '10:00',
            'return_time' => '12:00',
            'activity_type' => ReservationActivityType::STANDARD->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->assertJsonPath('data.conflicts.0.message', 'Le creneau chevauche une reservation plus prioritaire selon le reglement.');
    }

    public function test_user_cannot_create_for_unattached_organization(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $organization = $this->seededOrganization('Ecole communale de Dinant');

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', $this->payload($organization))
            ->assertForbidden();
    }

    public function test_non_school_reservation_requires_favorable_council_decision_before_approval(): void
    {
        Http::fake([
            'https://ors.test/*' => Http::response([
                'routes' => [[
                    'summary' => [
                        'distance' => 12000,
                        'duration' => 1800,
                    ],
                ]],
            ]),
        ]);

        $agent = $this->seededUser('agent.test@bus-communal.local');
        $organization = $this->seededOrganization('ASBL Les Amis du Parc');

        Sanctum::actingAs($agent);

        $reservationId = $this->postJson('/api/reservations', $this->payload($organization))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->json('data.id');

        $this->patchJson("/api/reservations/{$reservationId}/approve")
            ->assertUnprocessable();

        $this->postJson("/api/reservations/{$reservationId}/council-decision", [
            'decision_status' => CouncilDecisionStatus::APPROUVE->value,
            'decision_date' => '2026-06-01',
            'decision_reference' => 'COL-2026-001',
        ])->assertOk();

        $this->patchJson("/api/reservations/{$reservationId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);
    }

    public function test_non_school_reservation_after_allowed_return_time_creates_derogation_conflict_resolved_by_council(): void
    {
        Http::fake([
            'https://ors.test/*' => Http::response([
                'routes' => [[
                    'summary' => [
                        'distance' => 12000,
                        'duration' => 1800,
                    ],
                ]],
            ]),
        ]);

        $agent = $this->seededUser('agent.test@bus-communal.local');
        $organization = $this->seededOrganization('ASBL Les Amis du Parc');

        Sanctum::actingAs($agent);

        $reservationId = $this->postJson('/api/reservations', [
            ...$this->payload($organization),
            'return_time' => '16:30',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->json('data.id');

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $reservationId,
            'type' => ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value,
            'is_resolved' => false,
        ]);

        $this->postJson("/api/reservations/{$reservationId}/council-decision", [
            'decision_status' => CouncilDecisionStatus::APPROUVE->value,
            'decision_date' => '2026-06-01',
            'decision_reference' => 'COL-2026-002',
            'notes' => 'Derogation horaire acceptee.',
        ])->assertOk();

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $reservationId,
            'type' => ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value,
            'is_resolved' => true,
            'resolved_by_user_id' => $agent->id,
        ]);

        $this->patchJson("/api/reservations/{$reservationId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);
    }

    public function test_weekend_creation_is_rejected(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', [
            ...$this->payload($organization),
            'trip_date' => '2026-06-13',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trip_date');
    }

    public function test_reservation_requires_minimum_notice(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', [
            ...$this->payload($organization),
            'trip_date' => now()->addDays(14)->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trip_date');
    }

    public function test_reservation_requires_belgian_locations(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $organization = $this->seededOrganization('Ecole communale de Dinant');
        [$pickup, $destination] = $this->seededLocations();
        $destination->update(['country' => 'France']);

        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', $this->payload($organization, $pickup, $destination))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination_location_id');
    }

    private function payload(Organization $organization, ?Location $pickup = null, ?Location $destination = null): array
    {
        [$pickup, $destination] = $pickup && $destination ? [$pickup, $destination] : $this->seededLocations();

        return [
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00',
            'return_time' => '11:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie educative',
            'activity_type' => ReservationActivityType::STANDARD->value,
        ];
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededOrganization(string $name): Organization
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
