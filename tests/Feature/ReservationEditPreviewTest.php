<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Http\Requests\Reservations\CheckTripRequest;
use App\Http\Requests\Reservations\PreviewReservationRequest;
use App\Http\Requests\Reservations\StoreReservationRequest;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\ReservationStatusHistory;
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
use Tests\Concerns\SaturatesFleet;
use Tests\TestCase;

/**
 * reservation_id sur preview et check-trip : vérifier une modification comme le fera le PATCH,
 * sans que la réservation n'entre en conflit avec elle-même ni ne compte dans les quotas.
 */
class ReservationEditPreviewTest extends TestCase
{
    use DatabaseTransactions;
    use SaturatesFleet;

    private Organization $asbl;

    private User $manager;

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
        Http::fake([
            'https://ors.test/*' => Http::response(['routes' => [['summary' => ['distance' => 12000, 'duration' => 1800]]]]),
        ]);

        $this->asbl = Organization::query()->where('name', 'ASBL Les Amis du Parc')->firstOrFail();
        $this->manager = User::query()->where('email', 'responsable.test@bus-communal.local')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_edited_reservation_does_not_conflict_with_itself(): void
    {
        // Réservation approuvée qui occupe le seul chauffeur : seul un agent peut encore la modifier.
        $reservation = $this->saturateFleet($this->reservation('2026-05-11'), ReservationStatus::APPROUVE);
        Sanctum::actingAs(User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail());

        $without = $this->postJson('/api/reservations/preview', $this->previewPayload())->assertOk();
        $this->assertSame($reservation->id, $without->json('data.availability.blocking_reservation_id'));
        $this->assertSame(ReservationConflictType::SCHEDULE_OVERLAP->value, $without->json('data.conflicts.0.type'));

        $this->postJson('/api/reservations/preview', $this->previewPayload(['reservation_id' => $reservation->id]))
            ->assertOk()
            ->assertJsonPath('data.availability.is_available', true)
            ->assertJsonPath('data.availability.blocking_reservation_id', null)
            ->assertJsonPath('data.conflicts', []);

        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['reservation_id' => $reservation->id]))
            ->assertOk()
            ->assertJsonPath('data.availability.is_available', true)
            ->assertJsonPath('data.conflicts', []);
    }

    public function test_edited_reservation_is_excluded_from_pending_quota_in_preview_and_patch(): void
    {
        // L'ASBL a atteint le maximum de 2 demandes en attente.
        $edited = $this->reservation('2026-05-11');
        $this->reservation('2026-05-12');
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['trip_date' => '2026-05-13']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');

        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['trip_date' => '2026-05-13', 'reservation_id' => $edited->id]))
            ->assertOk();

        // Le PATCH applique la même exclusion (il se bloquait auparavant sur son propre quota).
        $this->patchJson("/api/reservations/{$edited->id}", ['trip_date' => '2026-05-13'])
            ->assertOk()
            ->assertJsonPath('data.trip_date', '2026-05-13');
    }

    public function test_agent_can_modify_a_reservation_inside_the_minimum_notice(): void
    {
        // Lundi 20/04 : le jeudi 23/04 est à J+3, bien en deçà des 15 jours de préavis
        $reservation = $this->reservation('2026-04-23');
        Sanctum::actingAs(User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail());

        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['trip_date' => '2026-04-23', 'reservation_id' => $reservation->id]))
            ->assertOk();
        $this->postJson('/api/reservations/preview', $this->previewPayload(['trip_date' => '2026-04-22', 'reservation_id' => $reservation->id]))
            ->assertOk();

        $this->patchJson("/api/reservations/{$reservation->id}", ['departure_time' => '10:00', 'return_time' => '12:00'])
            ->assertOk()
            ->assertJsonPath('data.departure_time', '10:00:00');

        // Les autres règles bloquantes restent : samedi 25/04 refusé
        $this->patchJson("/api/reservations/{$reservation->id}", ['trip_date' => '2026-04-25'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.trip_date', ['Les réservations ne sont pas autorisées le week-end.']);
    }

    public function test_external_user_keeps_the_minimum_notice_only_when_the_trip_date_changes(): void
    {
        // Jeudi 30/04 = J+10
        $reservation = $this->reservation('2026-04-30');
        Sanctum::actingAs($this->manager);

        // Date inchangée : accepté
        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['trip_date' => '2026-04-30', 'reservation_id' => $reservation->id]))
            ->assertOk();
        $this->patchJson("/api/reservations/{$reservation->id}", ['trip_date' => '2026-04-30', 'trip_purpose' => 'Sortie au musée'])
            ->assertOk()
            ->assertJsonPath('data.trip_purpose', 'Sortie au musée');

        // Nouvelle date à J+9 : préavis appliqué
        $this->postJson('/api/reservations/preview', $this->previewPayload(['trip_date' => '2026-04-29', 'reservation_id' => $reservation->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trip_date');
        $this->patchJson("/api/reservations/{$reservation->id}", ['trip_date' => '2026-04-29'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.trip_date', ['La demande doit être introduite au moins 15 jours avant la date souhaitée.']);

        $this->assertSame('2026-04-30', $reservation->refresh()->trip_date->toDateString());
    }

    public function test_creation_keeps_the_minimum_notice_for_everyone(): void
    {
        foreach (['responsable.test@bus-communal.local', 'agent.test@bus-communal.local'] as $email) {
            Sanctum::actingAs(User::query()->where('email', $email)->firstOrFail());

            $this->postJson('/api/reservations', $this->previewPayload(['trip_date' => '2026-04-30']))
                ->assertUnprocessable()
                ->assertJsonPath('errors.trip_date', ['La demande doit être introduite au moins 15 jours avant la date souhaitée.']);
        }
    }

    public function test_user_who_cannot_update_the_reservation_gets_403(): void
    {
        $reservation = $this->reservation('2026-05-11');

        // Simple membre de l'ASBL : peut réserver pour elle, mais pas modifier la réservation d'un autre.
        $member = User::factory()->create();
        $member->assignRole('user');
        $member->organizations()->attach($this->asbl->id, ['relationship_type' => 'prof', 'is_default' => true]);
        Sanctum::actingAs($member);

        $this->postJson('/api/reservations/preview', $this->previewPayload(['reservation_id' => $reservation->id]))->assertForbidden();
        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['reservation_id' => $reservation->id]))->assertForbidden();
    }

    public function test_cancelled_reservation_cannot_be_checked_for_edition(): void
    {
        $reservation = $this->reservation('2026-05-11', ReservationStatus::ANNULE);
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['reservation_id' => $reservation->id]))->assertForbidden();
    }

    public function test_organization_must_be_the_one_of_the_reservation(): void
    {
        $reservation = $this->reservation('2026-05-11');
        $other = Organization::create([
            'name' => 'ASBL Autre',
            'type' => OrganizationType::ASBL,
            'official_email' => 'autre@asbl.test',
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);
        $this->manager->organizations()->attach($other->id, ['relationship_type' => 'responsable', 'is_default' => false]);
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/reservations/check-trip', $this->tripPayload([
            'organization_id' => $other->id,
            'reservation_id' => $reservation->id,
        ]))->assertUnprocessable()
            ->assertJsonPath('errors.organization_id.0', 'L’organisation doit être celle de la réservation modifiée.');
    }

    public function test_unknown_reservation_id_is_rejected(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['reservation_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation_id');
    }

    public function test_reservation_of_another_organization_gets_403(): void
    {
        $school = Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail();
        $schoolReservation = $this->reservation('2026-05-11', organization: $school,
            requester: User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail());
        Sanctum::actingAs($this->manager);

        // Organisation envoyée = la sienne (ASBL), réservation = celle d'une école dont il n'est pas membre.
        $this->postJson('/api/reservations/preview', $this->previewPayload(['reservation_id' => $schoolReservation->id]))->assertForbidden();
        $this->postJson('/api/reservations/check-trip', $this->tripPayload(['reservation_id' => $schoolReservation->id]))->assertForbidden();
    }

    public function test_conflicting_asbl_reservation_fixed_by_update_goes_back_to_pending(): void
    {
        $school = Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail();
        $blocking = $this->saturateFleet($this->reservation('2026-05-11', organization: $school,
            requester: User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail()));
        $conflicting = $this->conflictingReservation($this->asbl, $blocking);
        Sanctum::actingAs($this->manager);

        // Toujours en conflit si le créneau ne change pas.
        $this->patchJson("/api/reservations/{$conflicting->id}", ['trip_purpose' => 'Sortie au musée'])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::EN_CONFLIT->value);

        // Créneau libre : plus de conflit, retour en attente de validation.
        $this->patchJson("/api/reservations/{$conflicting->id}", ['departure_time' => '13:00', 'return_time' => '15:00'])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::EN_ATTENTE->value)
            ->assertJsonPath('data.conflicts', []);
    }

    public function test_conflicting_school_reservation_fixed_by_update_is_approved_and_assigned_again(): void
    {
        $school = Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail();
        $requester = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();
        $blocking = $this->reservation('2026-05-11', organization: $this->asbl);
        $conflicting = $this->conflictingReservation($school, $blocking, $requester);
        Sanctum::actingAs($requester);

        $this->patchJson("/api/reservations/{$conflicting->id}", ['departure_time' => '13:00', 'return_time' => '15:00'])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::CONFIRME->value)
            ->assertJsonPath('data.conflicts', []);
    }

    public function test_reservation_id_is_not_accepted_on_creation(): void
    {
        $this->assertArrayHasKey('reservation_id', (new PreviewReservationRequest())->rules());
        $this->assertArrayHasKey('reservation_id', (new CheckTripRequest())->rules());
        $this->assertArrayNotHasKey('reservation_id', (new StoreReservationRequest())->rules());
    }

    private function reservation(
        string $date,
        ReservationStatus $status = ReservationStatus::EN_ATTENTE,
        ?Organization $organization = null,
        ?User $requester = null,
    ): Reservation {
        [$pickup, $destination] = $this->locations();

        return Reservation::create([
            'requested_by_user_id' => ($requester ?? $this->manager)->id,
            'organization_id' => ($organization ?? $this->asbl)->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => $date,
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'activity_type' => ReservationActivityType::STANDARD,
            'estimated_duration_min' => 30,
            'status' => $status,
            'approval_type' => 'manuel',
        ]);
    }

    /** Réservation en conflit de chevauchement avec $blocking, comme créée par ReservationService. */
    private function conflictingReservation(Organization $organization, Reservation $blocking, ?User $requester = null): Reservation
    {
        $reservation = $this->reservation('2026-05-11', ReservationStatus::EN_CONFLIT, $organization, $requester);

        ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            // À la création, ReservationService::recordStatus enregistre old_status = new_status.
            'old_status' => ReservationStatus::EN_CONFLIT,
            'new_status' => ReservationStatus::EN_CONFLIT,
            'changed_by_user_id' => $reservation->requested_by_user_id,
            'reason' => 'Création de la demande',
        ]);
        ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'type' => ReservationConflictType::SCHEDULE_OVERLAP,
            'message' => 'Chevauchement',
            'related_reservation_id' => $blocking->id,
        ]);

        return $reservation;
    }

    private function tripPayload(array $overrides = []): array
    {
        [$pickup, $destination] = $this->locations();

        return array_merge([
            'organization_id' => $this->asbl->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => '2026-05-11',
            'departure_time' => '09:00',
            'return_time' => '11:00',
        ], $overrides);
    }

    private function previewPayload(array $overrides = []): array
    {
        return $this->tripPayload([
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            ...$overrides,
        ]);
    }

    /** @return array{0: Location, 1: Location} */
    private function locations(): array
    {
        return [
            Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail(),
            Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail(),
        ];
    }
}
