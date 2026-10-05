<?php

namespace Tests\Feature;

use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationConflict;
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
use App\Models\CouncilDecision;
use App\Models\Driver;

/**
 * Un chevauchement horaire n'est un conflit que si la flotte n'a plus de chauffeur ou de véhicule libre
 * sur le créneau. Avec la flotte de test (2 chauffeurs, 2 véhicules), deux réservations simultanées
 * cohabitent ; pour obtenir un vrai chevauchement, on réduit la flotte à un chauffeur (`singleDriver()`).
 *
 * Couvre :
 * 1. Chevauchement toléré tant que la flotte peut l'absorber (réservation en attente ou confirmée).
 * 2. Hard-block (422) à la création quand une réservation approuvée/confirmée occupe tout le créneau.
 * 3. Conflit doux obtenu par modification (PATCH) vers un créneau occupé : résolution manuelle refusée
 *    tant que le chevauchement est réel, auto-résolution quand la réservation bloquante devient terminale.
 * 4. Régression « école + ASBL » : conflit orphelin après affectation / approbation.
 *
 * Date de référence (Carbon::setTestNow = 2026-04-20) : 2026-06-16 (mardi, loin du préavis de 15 j).
 */
class ReservationConflictResolutionTest extends TestCase
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
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // 1. Flotte libre : le chevauchement horaire n'est pas un conflit
    // =========================================================================

    public function test_overlapping_school_reservation_is_confirmed_on_other_resources_when_fleet_is_free(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        Sanctum::actingAs($agent);
        $asblId = $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->json('data.id');

        $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value)
            ->assertJsonPath('data.conflicts', []);

        // L'ASBL, approuvée ensuite par le collège, est confirmée avec les autres ressources.
        $this->councilApproves($asblId);
        $this->patchJson("/api/reservations/{$asblId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);

        $reservations = Reservation::query()->where('trip_date', '2026-06-16')->get();
        $this->assertCount(2, $reservations->pluck('driver_id')->unique());
        $this->assertCount(2, $reservations->pluck('vehicle_id')->unique());
    }

    // =========================================================================
    // 2. Flotte épuisée : hard-block à la création
    // =========================================================================

    public function test_creating_reservation_on_confirmed_slot_is_rejected_when_fleet_is_exhausted(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $this->singleDriver();
        $agent  = $this->user('agent.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');

        Sanctum::actingAs($agent);
        $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);

        $this->postJson('/api/reservations', $this->payload($school))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');
    }

    // =========================================================================
    // 3. Conflit doux (modification vers un créneau occupé) : résolution manuelle et automatique
    // =========================================================================

    public function test_cannot_manually_resolve_schedule_overlap_while_blocking_reservation_is_active(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        [, $bId] = $this->blockedByConfirmedSchool($this->org('ASBL Les Amis du Parc'));

        $conflict = ReservationConflict::query()
            ->where('reservation_id', $bId)
            ->where('type', ReservationConflictType::SCHEDULE_OVERLAP->value)
            ->firstOrFail();

        $this->patchJson("/api/reservations/{$bId}/conflicts/{$conflict->id}/resolve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conflict');
    }

    public function test_cannot_approve_conflicting_reservation_while_overlap_is_real(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        [, $bId] = $this->blockedByConfirmedSchool($this->org('ASBL Les Amis du Parc'));
        $this->councilApproves($bId);

        $this->patchJson("/api/reservations/{$bId}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        $this->assertDatabaseHas('reservations', ['id' => $bId, 'status' => ReservationStatus::EN_CONFLIT->value]);
    }

    public function test_can_manually_resolve_schedule_overlap_after_blocking_reservation_becomes_terminal(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        [$aId, $bId] = $this->blockedByConfirmedSchool($this->org('ASBL Les Amis du Parc'));

        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Test.'])->assertOk();

        $conflict = ReservationConflict::query()
            ->where('reservation_id', $bId)
            ->where('type', ReservationConflictType::SCHEDULE_OVERLAP->value)
            ->firstOrFail();

        $this->assertTrue($conflict->is_resolved); // auto-résolu lors du cancel
        $this->patchJson("/api/reservations/{$bId}/conflicts/{$conflict->id}/resolve")->assertOk();
    }

    public function test_cancelling_blocking_reservation_auto_confirms_school_reservation(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        [$aId, $bId] = $this->blockedByConfirmedSchool($this->org('Ecole communale de Dinant'));

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id'         => $bId,
            'type'                   => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'            => false,
            'related_reservation_id' => $aId,
        ]);

        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Annulation test.'])->assertOk();

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);
        $this->assertDatabaseHas('reservations', ['id' => $bId, 'status' => ReservationStatus::CONFIRME->value]);
    }

    public function test_rejecting_blocking_reservation_auto_confirms_school_reservation(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        // A (ASBL) bloquante : approuvée par le collège puis confirmée, avant de bloquer B (école).
        $this->singleDriver();
        $agent  = $this->user('agent.test@bus-communal.local');
        $asbl   = $this->org('ASBL Les Amis du Parc');
        $school = $this->org('Ecole communale de Dinant');

        Sanctum::actingAs($agent);
        $aId = $this->postJson('/api/reservations', $this->payload($asbl))->assertCreated()->json('data.id');
        $this->councilApproves($aId);
        $this->patchJson("/api/reservations/{$aId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);

        $bId = $this->postJson('/api/reservations', $this->payload($school, ['departure_time' => '14:00', 'return_time' => '15:00']))
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/reservations/{$bId}", ['departure_time' => '09:00', 'return_time' => '11:00'])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value);

        // A repasse « approuvée » (en attente d'affectation) puis le collège la refuse : le créneau de B se libère.
        Reservation::findOrFail($aId)->update(['status' => ReservationStatus::APPROUVE]);
        $this->patchJson("/api/reservations/{$aId}/reject", ['reason' => 'Demande refusée.'])->assertOk();

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);
        $this->assertDatabaseHas('reservations', ['id' => $bId, 'status' => ReservationStatus::CONFIRME->value]);
    }

    public function test_cancelling_blocking_reservation_sets_non_school_reservation_back_to_pending(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        [$aId, $bId] = $this->blockedByConfirmedSchool($this->org('ASBL Les Amis du Parc'));

        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Annulation test.'])->assertOk();

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);
        $this->assertDatabaseHas('reservations', ['id' => $bId, 'status' => ReservationStatus::EN_ATTENTE->value]);
    }

    public function test_auto_resolution_only_clears_schedule_overlap_not_other_conflicts(): void
    {
        // RETURN_AFTER_ALLOWED_TIME est un warning de dérogation, non persisté : on injecte un second
        // conflit réel (DISTANCE_NOT_CALCULATED) pour isoler le comportement d'auto-résolution.
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        [$aId, $bId] = $this->blockedByConfirmedSchool($this->org('ASBL Les Amis du Parc'));

        ReservationConflict::create([
            'reservation_id'         => $bId,
            'type'                   => ReservationConflictType::DISTANCE_NOT_CALCULATED->value,
            'message'                => 'Distance non calculee (conflit injecte pour le test).',
            'related_reservation_id' => null,
            'is_resolved'            => false,
        ]);

        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Annulation test.'])->assertOk();

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);
        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::DISTANCE_NOT_CALCULATED->value,
            'is_resolved'    => false,
        ]);
        $this->assertDatabaseHas('reservations', ['id' => $bId, 'status' => ReservationStatus::EN_CONFLIT->value]);
    }

    // =========================================================================
    // 4. Régression : conflit de chevauchement orphelin (flotte pourtant disponible)
    // =========================================================================

    public function test_manual_assign_resolves_stale_overlap_and_confirms_approved_reservation(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        Sanctum::actingAs($agent);
        $asblId = $this->postJson('/api/reservations', $this->payload($asbl))->assertCreated()->json('data.id');
        $reservation = Reservation::findOrFail(
            $this->postJson('/api/reservations', $this->payload($school))->assertCreated()->json('data.id')
        );

        // État laissé par l'ancien comportement : approuvée, affectée, mais conflit de chevauchement ouvert.
        $reservation->update(['status' => ReservationStatus::APPROUVE]);
        $conflict = ReservationConflict::create([
            'reservation_id'         => $reservation->id,
            'type'                   => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'message'                => 'Chevauchement',
            'related_reservation_id' => $asblId,
        ]);

        $this->patchJson("/api/reservations/{$reservation->id}/assign", ['vehicle_id' => $reservation->vehicle_id])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);

        $this->assertTrue($conflict->refresh()->is_resolved);
    }

    public function test_approving_conflicting_reservation_re_evaluates_a_stale_overlap(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent = $this->user('agent.test@bus-communal.local');
        $asbl  = $this->org('ASBL Les Amis du Parc');

        Sanctum::actingAs($agent);
        $other = $this->postJson('/api/reservations', $this->payload($asbl))->assertCreated()->json('data.id');
        $bId   = $this->postJson('/api/reservations', $this->payload($asbl))->assertCreated()->json('data.id');

        // B a été marquée en conflit par l'ancien comportement alors que la flotte était libre.
        $b = Reservation::findOrFail($bId);
        $b->update(['status' => ReservationStatus::EN_CONFLIT]);
        $conflict = ReservationConflict::create([
            'reservation_id'         => $bId,
            'type'                   => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'message'                => 'Chevauchement',
            'related_reservation_id' => $other,
        ]);

        $this->councilApproves($bId);
        $this->patchJson("/api/reservations/{$bId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);

        $this->assertTrue($conflict->refresh()->is_resolved);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Flotte réduite à un chauffeur : un seul trajet à la fois. */
    private function singleDriver(): void
    {
        $first = Driver::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        Driver::query()->whereKeyNot($first->id)->update(['is_active' => false]);
    }

    private function councilApproves(int $reservationId): void
    {
        CouncilDecision::create([
            'reservation_id'      => $reservationId,
            'decision_status'     => \App\Enums\CouncilDecisionStatus::APPROUVE,
            'recorded_by_user_id' => $this->user('agent.test@bus-communal.local')->id,
            'validated_at'        => now(),
        ]);
    }

    /**
     * A (école) confirmée de 09:00 à 11:00 sur le seul chauffeur, puis B ($organization) déplacée
     * sur ce créneau : B passe EN_CONFLIT avec un SCHEDULE_OVERLAP vers A. Retourne [A, B].
     *
     * @return array{0: int, 1: int}
     */
    private function blockedByConfirmedSchool(Organization $organization): array
    {
        $this->singleDriver();
        $agent  = $this->user('agent.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');

        Sanctum::actingAs($agent);
        $aId = $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value)
            ->json('data.id');

        $bId = $this->postJson('/api/reservations', $this->payload($organization, ['departure_time' => '14:00', 'return_time' => '15:30']))
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/reservations/{$bId}", ['departure_time' => '09:00', 'return_time' => '11:00'])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value);

        return [$aId, $bId];
    }

    private function payload(Organization $org, array $overrides = []): array
    {
        [$pickup, $destination] = $this->locations();

        return array_merge([
            'organization_id'         => $org->id,
            'pickup_location_id'      => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date'               => '2026-06-16',
            'departure_time'          => '09:00',
            'return_time'             => '11:00',
            'nb_children'             => 10,
            'nb_adults'               => 1,
            'trip_purpose'            => 'Sortie test',
            'activity_type'           => ReservationActivityType::STANDARD->value,
        ], $overrides);
    }

    private function orsOk(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['routes' => [['summary' => ['distance' => 12000, 'duration' => 1800]]]]);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function org(string $name): Organization
    {
        return Organization::query()->where('name', $name)->firstOrFail();
    }

    private function locations(): array
    {
        return [
            Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail(),
            Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail(),
        ];
    }
}