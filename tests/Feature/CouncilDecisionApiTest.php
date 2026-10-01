<?php

namespace Tests\Feature;

use App\Enums\CouncilDecisionStatus;
use App\Enums\DocumentType;
use App\Enums\ReservationActivityType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Documents\DeliberationDocumentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CouncilDecisionApiTest extends TestCase
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

    public function test_internal_user_can_list_and_show_council_decisions(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $decision = $this->createCouncilDecisionForNonSchoolReservation();

        Sanctum::actingAs($agent);

        $this->getJson('/api/council-decisions')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $decision->id,
                'reservation_id' => $decision->reservation_id,
            ]);

        $this->getJson("/api/council-decisions/{$decision->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $decision->id)
            ->assertJsonPath('data.decision_status', CouncilDecisionStatus::APPROUVE->value);
    }

    public function test_internal_user_can_filter_council_decisions_by_status(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $approvedDecision = $this->createCouncilDecisionForNonSchoolReservation();
        $refusedDecision = $this->createCouncilDecisionForNonSchoolReservation('ASBL Refusee');
        $refusedDecision->update(['decision_status' => CouncilDecisionStatus::REFUSE]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/council-decisions?decision_status=refuse')
            ->assertOk()
            ->assertJsonFragment(['id' => $refusedDecision->id])
            ->assertJsonMissing(['id' => $approvedDecision->id]);

        $this->getJson('/api/council-decisions?decision_status=approuve')
            ->assertOk()
            ->assertJsonFragment(['id' => $approvedDecision->id])
            ->assertJsonMissing(['id' => $refusedDecision->id]);

        $this->getJson('/api/council-decisions?decision_status=inconnu')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_status');
    }

    public function test_reservations_can_be_filtered_on_awaiting_council_decision(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');

        // Décision approuvée : le Collège a tranché, la réservation n'attend plus
        $approvedDecision = $this->createCouncilDecisionForNonSchoolReservation();
        // Décision encore en attente : la réservation attend toujours le Collège
        $pendingDecision = $this->createCouncilDecisionForNonSchoolReservation('ASBL En Attente');
        $pendingDecision->update(['decision_status' => CouncilDecisionStatus::EN_ATTENTE]);
        // Aucune décision : la réservation attend le Collège
        $withoutDecision = $this->createCouncilDecisionForNonSchoolReservation('ASBL Sans Decision');
        $reservationWithoutDecisionId = $withoutDecision->reservation_id;
        $withoutDecision->delete();

        Sanctum::actingAs($agent);

        $ids = collect($this->getJson('/api/reservations?council=a_decider')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($pendingDecision->reservation_id));
        $this->assertTrue($ids->contains($reservationWithoutDecisionId));
        $this->assertFalse($ids->contains($approvedDecision->reservation_id));

        $this->getJson('/api/reservations?council=autre')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('council');
    }

    public function test_reservation_list_and_detail_expose_the_council_decision_status(): void
    {
        $approvedDecision = $this->createCouncilDecisionForNonSchoolReservation();
        $withoutDecision = $this->createCouncilDecisionForNonSchoolReservation('ASBL Sans Decision');
        $reservationWithoutDecisionId = $withoutDecision->reservation_id;
        $withoutDecision->delete();

        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));

        $listed = function (int $reservationId): array {
            $organizationId = Reservation::query()->findOrFail($reservationId)->organization_id;

            return collect($this->getJson("/api/reservations?organization_id={$organizationId}")->assertOk()->json('data'))
                ->firstWhere('id', $reservationId);
        };

        $this->assertSame(CouncilDecisionStatus::APPROUVE->value, $listed($approvedDecision->reservation_id)['council_decision_status']);
        // Pas de décision : la clé est présente et vaut null
        $this->assertArrayHasKey('council_decision_status', $listed($reservationWithoutDecisionId));
        $this->assertNull($listed($reservationWithoutDecisionId)['council_decision_status']);

        $this->getJson("/api/reservations/{$approvedDecision->reservation_id}")
            ->assertOk()
            ->assertJsonPath('data.council_decision_status', CouncilDecisionStatus::APPROUVE->value);
    }

    public function test_past_trips_are_no_longer_awaiting_council_decision(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $withoutDecision = $this->createCouncilDecisionForNonSchoolReservation('ASBL Trajet Passe');
        $reservationId = $withoutDecision->reservation_id;
        $withoutDecision->delete();

        Sanctum::actingAs($agent);

        // Le jour même du trajet (16/06, 22:00 à Bruxelles = 20:00 UTC), la réservation attend encore le Collège
        Carbon::setTestNow('2026-06-16 20:00:00');
        $ids = collect($this->getJson('/api/reservations?council=a_decider')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($reservationId));

        // Le lendemain à Bruxelles (00:30 le 17/06 = 22:30 UTC le 16/06), le trajet est passé :
        // elle n'apparaît plus et aucune décision ne peut être encodée
        Carbon::setTestNow('2026-06-16 22:30:00');
        $ids = collect($this->getJson('/api/reservations?council=a_decider')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($reservationId));

        $this->postJson('/api/council-decisions', [
            'reservation_id' => $reservationId,
            'decision_status' => CouncilDecisionStatus::APPROUVE->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
    }

    public function test_only_notes_can_change_once_the_trip_is_past(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $decision = $this->createCouncilDecisionForNonSchoolReservation();

        Sanctum::actingAs($agent);
        Carbon::setTestNow('2026-06-17 08:00:00');

        $unchanged = [
            'decision_status' => CouncilDecisionStatus::APPROUVE->value,
            'decision_date' => '2026-06-01',
            'decision_reference' => 'COL-2026-050',
        ];

        $this->patchJson("/api/council-decisions/{$decision->id}", [...$unchanged, 'notes' => 'Note corrigée après le trajet.'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Note corrigée après le trajet.')
            ->assertJsonPath('data.decision_status', CouncilDecisionStatus::APPROUVE->value);

        // Même date envoyée en ISO avec l'heure, mêmes notes : accepté, sans nouvel événement d'audit
        $this->patchJson("/api/council-decisions/{$decision->id}", [
            ...$unchanged,
            'decision_date' => '2026-06-01T00:00:00Z',
            'notes' => 'Note corrigée après le trajet.',
        ])->assertOk();
        $this->assertSame(1, DB::table('domain_events')
            ->where('aggregate_type', 'council_decision')
            ->where('aggregate_id', $decision->id)
            ->where('event_type', 'council_decision.notes_updated')
            ->count());

        $this->patchJson("/api/council-decisions/{$decision->id}", [...$unchanged, 'decision_date' => '2026-06-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        $this->patchJson("/api/council-decisions/{$decision->id}", [...$unchanged, 'decision_status' => CouncilDecisionStatus::REFUSE->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        $this->patchJson("/api/council-decisions/{$decision->id}", [...$unchanged, 'decision_reference' => 'COL-AUTRE'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        $this->assertDatabaseHas('council_decisions', [
            'id' => $decision->id,
            'decision_status' => CouncilDecisionStatus::APPROUVE->value,
            'decision_reference' => 'COL-2026-050',
        ]);
    }

    public function test_user_can_view_council_decision_for_visible_reservation_only(): void
    {
        $user = $this->seededUser('user.test@bus-communal.local');
        $visibleDecision = $this->createCouncilDecisionForNonSchoolReservation('ASBL Les Amis du Parc', $user);
        $hiddenDecision = $this->createCouncilDecisionForNonSchoolReservation('ASBL Hidden');

        Sanctum::actingAs($user);

        $this->getJson('/api/council-decisions')
            ->assertOk()
            ->assertJsonFragment(['id' => $visibleDecision->id])
            ->assertJsonMissing(['id' => $hiddenDecision->id]);

        $this->getJson("/api/council-decisions/{$visibleDecision->id}")
            ->assertOk();

        $this->getJson("/api/council-decisions/{$hiddenDecision->id}")
            ->assertForbidden();
    }

    public function test_internal_user_can_update_existing_council_decision(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $decision = $this->createCouncilDecisionForNonSchoolReservation();
        $this->mockAutomaticDeliberationGeneration();

        Sanctum::actingAs($agent);

        $this->patchJson("/api/council-decisions/{$decision->id}", [
            'decision_status' => CouncilDecisionStatus::REFUSE->value,
            'decision_date' => '2026-06-03',
            'decision_reference' => 'COL-2026-099',
            'notes' => 'Refus motive.',
        ])
            ->assertOk()
            ->assertJsonPath('data.decision_status', CouncilDecisionStatus::REFUSE->value)
            ->assertJsonPath('data.decision_reference', 'COL-2026-099');

        $this->assertDatabaseHas('council_decisions', [
            'id' => $decision->id,
            'decision_status' => CouncilDecisionStatus::REFUSE->value,
            'decision_reference' => 'COL-2026-099',
        ]);
    }

    public function test_internal_user_creating_final_council_decision_generates_deliberation(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $organization = $this->seededOrganization('ASBL Les Amis du Parc');
        $this->fakeRouteCalculation();
        $this->mockAutomaticDeliberationGeneration();

        Sanctum::actingAs($agent);

        $reservationId = $this->postJson('/api/reservations', $this->payload($organization))
            ->assertCreated()
            ->json('data.id');

        $this->postJson('/api/council-decisions', [
            'reservation_id' => $reservationId,
            'decision_status' => CouncilDecisionStatus::APPROUVE->value,
            'decision_date' => '2026-06-03',
            'decision_reference' => 'COL-2026-100',
            'notes' => 'Decision favorable.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.decision_status', CouncilDecisionStatus::APPROUVE->value);
    }

    public function test_internal_user_creating_refused_council_decision_generates_deliberation(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        $organization = $this->seededOrganization('ASBL Les Amis du Parc');
        $this->fakeRouteCalculation();
        $this->mockAutomaticDeliberationGeneration();

        Sanctum::actingAs($agent);

        $reservationId = $this->postJson('/api/reservations', $this->payload($organization))
            ->assertCreated()
            ->json('data.id');

        $this->postJson('/api/council-decisions', [
            'reservation_id' => $reservationId,
            'decision_status' => CouncilDecisionStatus::REFUSE->value,
            'decision_date' => '2026-06-03',
            'decision_reference' => 'COL-2026-101',
            'notes' => 'Decision defavorable.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.decision_status', CouncilDecisionStatus::REFUSE->value);
    }

    private function createCouncilDecisionForNonSchoolReservation(
        string $organizationName = 'ASBL Les Amis du Parc',
        ?User $requestedByUser = null
    ): CouncilDecision
    {
        $this->fakeRouteCalculation();

        $agent = $this->seededUser('agent.test@bus-communal.local');
        $organization = $this->seededOrganization($organizationName);

        if ($organization === null) {
            $organization = Organization::create([
                'name' => $organizationName,
                'type' => 'asbl',
                'annual_quota' => 10,
            ]);
        }

        if ($requestedByUser !== null
            && ! $organization->users()->whereKey($requestedByUser->id)->exists()) {
            $organization->users()->attach($requestedByUser->id, [
                'relationship_type' => 'referent',
                'is_default' => true,
            ]);
        }

        Sanctum::actingAs($agent);

        $reservationId = $this->postJson('/api/reservations', $this->payload($organization, requestedByUser: $requestedByUser))
            ->assertCreated()
            ->json('data.id');

        return CouncilDecision::create([
            'reservation_id' => $reservationId,
            'decision_status' => CouncilDecisionStatus::APPROUVE,
            'decision_date' => '2026-06-01',
            'recorded_by_user_id' => $agent->id,
            'decision_reference' => 'COL-2026-050',
            'validated_at' => now(),
            'notes' => 'Decision test.',
        ]);
    }

    private function mockAutomaticDeliberationGeneration(): void
    {
        $document = new Document();
        $document->forceFill([
            'id' => 999,
            'document_type' => DocumentType::DELIBERATION,
        ]);

        $service = $this->createMock(DeliberationDocumentService::class);
        $service->expects($this->once())
            ->method('canGenerateFor')
            ->with($this->isInstanceOf(CouncilDecision::class))
            ->willReturn(true);
        $service->expects($this->once())
            ->method('generateFor')
            ->with($this->isInstanceOf(CouncilDecision::class))
            ->willReturn($document);

        $this->app->instance(DeliberationDocumentService::class, $service);
    }

    private function fakeRouteCalculation(): void
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
    }

    private function payload(
        Organization $organization,
        ?Location $pickup = null,
        ?Location $destination = null,
        ?User $requestedByUser = null
    ): array
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
            ...($requestedByUser ? ['requested_by_user_id' => $requestedByUser->id] : []),
        ];
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function seededOrganization(string $name): ?Organization
    {
        return Organization::query()->where('name', $name)->first();
    }

    private function seededLocations(): array
    {
        return [
            Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail(),
            Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail(),
        ];
    }
}
