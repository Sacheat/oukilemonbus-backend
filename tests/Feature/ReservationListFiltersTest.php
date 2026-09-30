<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api/reservations : filtre statuses[] et recherche q.
 */
class ReservationListFiltersTest extends TestCase
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
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_statuses_combine_with_date_from_and_sort(): void
    {
        $school = $this->organization('Ecole communale de Dinant');
        $pendingLate = $this->reservation($school, '2026-06-20', ReservationStatus::EN_ATTENTE);
        $conflictEarly = $this->reservation($school, '2026-06-12', ReservationStatus::EN_CONFLIT);
        $this->reservation($school, '2026-06-15', ReservationStatus::CONFIRME);
        $this->reservation($school, '2026-06-05', ReservationStatus::EN_ATTENTE); // avant date_from

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->assertSame(
            [$conflictEarly->id, $pendingLate->id],
            $this->ids('/api/reservations?statuses[]=en_attente&statuses[]=en_conflit&date_from=2026-06-10&sort=asc')
        );
        $this->assertSame(
            [$pendingLate->id, $conflictEarly->id],
            $this->ids('/api/reservations?statuses[]=en_attente&statuses[]=en_conflit&date_from=2026-06-10')
        );

        $this->getJson('/api/reservations?statuses[]=en_attente&date_from=2026-06-10&page=1')
            ->assertOk()
            ->assertJsonPath('links.first', fn (string $url) => str_contains(urldecode($url), 'statuses[0]=en_attente'));
    }

    public function test_status_alone_keeps_working_and_cannot_be_combined_with_statuses(): void
    {
        $school = $this->organization('Ecole communale de Dinant');
        $pending = $this->reservation($school, '2026-06-20', ReservationStatus::EN_ATTENTE);
        $this->reservation($school, '2026-06-21', ReservationStatus::CONFIRME);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->assertSame([$pending->id], $this->ids('/api/reservations?status=en_attente'));

        $this->getJson('/api/reservations?status=en_attente&statuses[]=confirme')
            ->assertUnprocessable()
            ->assertJsonPath('errors.statuses.0', 'Utilisez soit status, soit statuses[], pas les deux.');

        $this->getJson('/api/reservations?statuses[]=inconnu')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statuses.0');

        $this->getJson('/api/reservations?q='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_search_by_purpose_organization_requester_and_id(): void
    {
        $school = $this->organization('Ecole communale de Dinant');
        $asbl = $this->organization('ASBL Les Amis du Parc');
        $museum = $this->reservation($school, '2026-06-20', ReservationStatus::EN_ATTENTE, ['trip_purpose' => 'Visite du Musée des Beaux-Arts']);
        $park = $this->reservation($asbl, '2026-06-21', ReservationStatus::EN_ATTENTE, [
            'trip_purpose' => 'Pique-nique',
            'requested_by_user_id' => $this->user('responsable.test@bus-communal.local')->id,
        ]);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        // Motif, sans tenir compte de la casse ni des accents.
        $this->assertSame([$museum->id], $this->ids('/api/reservations?q=MUSEE'));
        // Nom de l'organisation.
        $this->assertSame([$park->id], $this->ids('/api/reservations?q=amis%20du%20parc'));
        // Demandeur : prénom et nom, ou email.
        $requester = $this->user('responsable.test@bus-communal.local');
        $this->assertSame([$park->id], $this->ids('/api/reservations?q='.urlencode($requester->first_name.' '.$requester->last_name)));
        $this->assertSame([$park->id], $this->ids('/api/reservations?q=responsable.test@'));
        // Id exact.
        $this->assertSame([$museum->id], $this->ids("/api/reservations?q={$museum->id}"));
        // Chaque mot doit correspondre ; jokers LIKE pris littéralement.
        $this->assertSame([], $this->ids('/api/reservations?q=musee%20pique'));
        $this->assertSame([], $this->ids('/api/reservations?q=%25'));
    }

    public function test_search_stays_within_the_external_user_scope(): void
    {
        $school = $this->organization('Ecole communale de Dinant');
        $asbl = $this->organization('ASBL Les Amis du Parc');
        $own = $this->reservation($school, '2026-06-20', ReservationStatus::EN_ATTENTE, ['trip_purpose' => 'Sortie piscine']);
        $other = $this->reservation($asbl, '2026-06-21', ReservationStatus::EN_ATTENTE, [
            'trip_purpose' => 'Sortie piscine',
            'requested_by_user_id' => $this->user('responsable.test@bus-communal.local')->id,
        ]);

        Sanctum::actingAs($this->user('user.test@bus-communal.local'));

        $this->assertSame([$own->id], $this->ids('/api/reservations?q=piscine'));
        $this->assertSame([], $this->ids("/api/reservations?q={$other->id}"));
    }

    /** @return list<int> */
    private function ids(string $uri): array
    {
        return array_column($this->getJson($uri)->assertOk()->json('data'), 'id');
    }

    private function reservation(Organization $organization, string $date, ReservationStatus $status, array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'trip_date' => $date,
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'status' => $status,
            'approval_type' => ApprovalType::MANUEL,
            ...$attributes,
        ]);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->where('name', $name)->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
