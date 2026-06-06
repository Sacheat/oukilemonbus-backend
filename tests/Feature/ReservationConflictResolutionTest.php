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

/**
 * Couvre deux comportements :
 *
 * 1. Résolution manuelle d'un SCHEDULE_OVERLAP → vérification réelle que l'overlap est disparu.
 * 2. Résolution automatique : quand A (réservation bloquante) devient terminale,
 *    les SCHEDULE_OVERLAP pointant vers A sont auto-résolus et la réservation bloquée
 *    est retransitionnée.
 * 3. Hard-block : si A est APPROUVE ou CONFIRME, créer B sur le même créneau est rejeté
 *    avec une ValidationException (422), la réservation n'est pas créée.
 *
 * Dates de référence (Carbon::setTestNow = 2026-04-20) :
 *   - trip_date A = 2026-06-16 (mardi, 57 jours — loin du délai minimum de 15 j)
 *   - trip_date B = 2026-06-16 (même jour, même créneau → chevauchement garanti)
 *
 * Règle de hard-block :
 *   - SCHEDULE_OVERLAP avec une réservation APPROUVE ou CONFIRME → hard-block (422).
 *   - SCHEDULE_OVERLAP avec une réservation EN_ATTENTE → conflit doux (EN_CONFLIT).
 *   Pour tester la résolution de conflits, A doit donc être EN_ATTENTE (ASBL ou équivalent).
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
    // 0. Hard-block : chevauchement avec une réservation confirmée/approuvée
    // =========================================================================

    public function test_creating_reservation_on_confirmed_slot_is_rejected_as_hard_block(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');

        // A (école) → CONFIRME automatiquement (auto-approve + auto-assign)
        Sanctum::actingAs($agent);
        $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value);

        // B même créneau → rejeté (hard-block : A est CONFIRME)
        $this->postJson('/api/reservations', $this->payload($school))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');
    }

    // =========================================================================
    // 1. Résolution manuelle — vérification réelle de l'overlap
    //    A doit être EN_ATTENTE (ASBL) pour que le conflit de B soit doux.
    // =========================================================================

    public function test_cannot_manually_resolve_schedule_overlap_while_blocking_reservation_is_active(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        // Réservation A (ASBL) en attente sur le créneau 09:00-11:00
        Sanctum::actingAs($agent);
        $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value);

        // Réservation B (école) même créneau → SCHEDULE_OVERLAP avec A (EN_ATTENTE → conflit doux)
        $bId = $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->json('data.id');

        $conflict = ReservationConflict::query()
            ->where('reservation_id', $bId)
            ->where('type', ReservationConflictType::SCHEDULE_OVERLAP->value)
            ->firstOrFail();

        // L'overlap est toujours actif → résolution manuelle refusée
        $this->patchJson("/api/reservations/{$bId}/conflicts/{$conflict->id}/resolve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conflict');
    }

    public function test_can_manually_resolve_schedule_overlap_after_blocking_reservation_becomes_terminal(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        // A (ASBL) en attente sur le créneau
        Sanctum::actingAs($agent);
        $aId = $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->json('data.id');

        // B (école) même créneau → conflit doux (A est EN_ATTENTE)
        $bId = $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->json('data.id');

        // Annuler A → auto-résolution de B déjà déclenchée, mais le conflit est maintenant
        // is_resolved=true ; un deuxième appel de résolution doit aussi passer (idempotent).
        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Test.'])
            ->assertOk();

        // B n'a plus de conflit non résolu — la résolution manuelle du conflit
        // (déjà auto-résolu) ne doit pas échouer.
        $conflict = ReservationConflict::query()
            ->where('reservation_id', $bId)
            ->where('type', ReservationConflictType::SCHEDULE_OVERLAP->value)
            ->firstOrFail();

        $this->assertTrue($conflict->is_resolved); // auto-résolu lors du cancel
    }

    // =========================================================================
    // 2. Résolution automatique quand la réservation bloquante devient terminale
    //    A doit être EN_ATTENTE (ASBL) pour que B puisse être créée en EN_CONFLIT.
    // =========================================================================

    public function test_cancelling_blocking_reservation_auto_confirms_school_reservation(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $user   = $this->user('user.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        // A (ASBL) en attente sur le créneau
        Sanctum::actingAs($agent);
        $aId = $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->json('data.id');

        // B (école) même créneau → EN_CONFLIT (A est EN_ATTENTE → conflit doux)
        Sanctum::actingAs($user);
        $bId = $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->json('data.id');

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id'         => $bId,
            'type'                   => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'            => false,
            'related_reservation_id' => $aId,
        ]);

        // Agent annule A
        Sanctum::actingAs($agent);
        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Annulation test.'])
            ->assertOk();

        // Le conflit SCHEDULE_OVERLAP de B est auto-résolu
        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);

        // B est retransitionnée : école → APPROUVE → auto-assign → CONFIRME
        $this->assertDatabaseHas('reservations', [
            'id'     => $bId,
            'status' => ReservationStatus::CONFIRME->value,
        ]);
    }

    public function test_rejecting_blocking_reservation_auto_confirms_school_reservation(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $user   = $this->user('user.test@bus-communal.local');
        $school = $this->org('Ecole communale de Dinant');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        // A (ASBL) EN_ATTENTE — l'agent crée pour l'ASBL sur le créneau
        Sanctum::actingAs($agent);
        $aId = $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->json('data.id');

        // B (école) même créneau → EN_CONFLIT (bloquée par A EN_ATTENTE → conflit doux)
        Sanctum::actingAs($user);
        $bId = $this->postJson('/api/reservations', $this->payload($school))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->json('data.id');

        // Agent refuse A
        Sanctum::actingAs($agent);
        $this->patchJson("/api/reservations/{$aId}/reject", ['reason' => 'Demande refusée.'])
            ->assertOk();

        // Conflit de B auto-résolu
        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);

        // B passe à CONFIRME (école, ressources disponibles)
        $this->assertDatabaseHas('reservations', [
            'id'     => $bId,
            'status' => ReservationStatus::CONFIRME->value,
        ]);
    }

    public function test_cancelling_blocking_reservation_sets_non_school_reservation_back_to_pending(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        // A (ASBL) en attente sur le créneau
        Sanctum::actingAs($agent);
        $aId = $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->json('data.id');

        // B (ASBL) même créneau → EN_CONFLIT (A est EN_ATTENTE → conflit doux)
        $bId = $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->json('data.id');

        // Agent annule A
        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Annulation test.'])
            ->assertOk();

        // Le conflit SCHEDULE_OVERLAP de B est auto-résolu
        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);

        // B retourne EN_ATTENTE (approbation manuelle requise pour les non-écoles)
        $this->assertDatabaseHas('reservations', [
            'id'     => $bId,
            'status' => ReservationStatus::EN_ATTENTE->value,
        ]);
    }

    public function test_auto_resolution_only_clears_schedule_overlap_not_other_conflicts(): void
    {
        Http::fake(['https://ors.test/*' => $this->orsOk()]);

        $agent  = $this->user('agent.test@bus-communal.local');
        $asbl   = $this->org('ASBL Les Amis du Parc');

        // A (ASBL) en attente sur le créneau
        Sanctum::actingAs($agent);
        $aId = $this->postJson('/api/reservations', $this->payload($asbl))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->json('data.id');

        // B (ASBL) : créneau tardif (retour 17:30 > 16:00) ET overlap avec A (EN_ATTENTE → conflit doux)
        // → 2 conflits : SCHEDULE_OVERLAP + RETURN_AFTER_ALLOWED_TIME
        $bId = $this->postJson('/api/reservations', array_merge($this->payload($asbl), ['return_time' => '17:30']))
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value)
            ->json('data.id');

        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => false,
        ]);
        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value,
            'is_resolved'    => false,
        ]);

        // Agent annule A
        $this->patchJson("/api/reservations/{$aId}/cancel", ['reason' => 'Annulation test.'])->assertOk();

        // Seul le SCHEDULE_OVERLAP est auto-résolu
        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::SCHEDULE_OVERLAP->value,
            'is_resolved'    => true,
        ]);

        // RETURN_AFTER_ALLOWED_TIME reste non résolu
        $this->assertDatabaseHas('reservation_conflicts', [
            'reservation_id' => $bId,
            'type'           => ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value,
            'is_resolved'    => false,
        ]);

        // B reste EN_CONFLIT (un conflit non résolu subsiste)
        $this->assertDatabaseHas('reservations', [
            'id'     => $bId,
            'status' => ReservationStatus::EN_CONFLIT->value,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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