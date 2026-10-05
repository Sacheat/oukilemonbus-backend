<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\ReservationQuotaService;
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
 * Page Organisations de /gestion : liste filtrée et triée, quota réel (même règle que le blocage), modification
 * tracée, bannissement avec annulation facultative des réservations à venir, notifications aux membres.
 * Organisations du seed : école (quota exempté), ASBL Les Amis du Parc (quota 5, un responsable), externe (quota 3).
 */
class OrganizationManagementApiTest extends TestCase
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
        Carbon::setTestNow('2026-06-10 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_agent_lists_organizations_with_search_filters_counts_and_quota(): void
    {
        $asbl = $this->asbl();
        $this->reservation($asbl, ['trip_date' => '2026-06-20', 'status' => ReservationStatus::APPROUVE]);
        $this->reservation($asbl, ['trip_date' => '2026-07-02', 'status' => ReservationStatus::EN_ATTENTE]);
        $this->reservation($asbl, ['trip_date' => '2026-03-02', 'status' => ReservationStatus::CONFIRME]); // passée
        $this->reservation($asbl, ['trip_date' => '2025-11-02', 'status' => ReservationStatus::CONFIRME]); // autre année
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $row = collect($this->getJson('/api/organizations?q=amis parc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('data'))->first();

        $this->assertSame($asbl->id, $row['id']);
        $this->assertSame(1, $row['members_count']);
        $this->assertSame(2, $row['upcoming_reservations_count']); // en attente et approuvée à venir
        $this->assertSame(['exempt' => false, 'used' => 2, 'limit' => 5, 'remaining' => 3, 'year' => 2026], $row['quota']);
        $this->assertSame(2, $row['reservations_used_this_year']);
        $this->assertTrue($row['rules']['council_required']);

        // Accents et casse ignorés, filtres type et bannies
        $this->getJson('/api/organizations?q=ÉCOLE')->assertOk()->assertJsonPath('data.0.name', 'Ecole communale de Dinant');
        $this->getJson('/api/organizations?type=externe')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/organizations?banned=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/organizations?sort=inconnu')->assertUnprocessable()->assertJsonValidationErrors('sort');

        // Tri par quota : l'ASBL (2/5) avant l'externe (0/3), l'école (exemptée) à la fin
        $names = collect($this->getJson('/api/organizations?sort=quota')->json('data'))->pluck('name');
        $this->assertSame('ASBL Les Amis du Parc', $names->first());
        $this->assertSame('Ecole communale de Dinant', $names->last());

        // Catalogue des types et de leurs règles (confirmation d'un changement de type)
        $types = collect($this->getJson('/api/organizations/types')->assertOk()->json('data'))->keyBy('value');
        $this->assertTrue($types['ecole_communale']['rules']['quota_exempt']);
        $this->assertTrue($types['asbl']['rules']['council_required']);

        // Tri par activité : l'ASBL a le plus de réservations à venir
        $this->getJson('/api/organizations?sort=activite')->assertJsonPath('data.0.name', 'ASBL Les Amis du Parc');
    }

    public function test_members_only_see_their_organizations_and_nested_organizations_have_no_quota(): void
    {
        $asbl = $this->asbl();
        $this->reservation($asbl, ['trip_date' => '2026-06-20']);
        Sanctum::actingAs($this->user('responsable.test@bus-communal.local'));

        $this->getJson('/api/organizations')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $asbl->id);

        // /auth/me : les organisations de l'utilisateur ont le vrai quota (jauge de l'espace usager)
        $mine = collect($this->getJson('/api/auth/me')->assertOk()->json('data.organizations'))->firstWhere('id', $asbl->id);
        $this->assertSame(0, $mine['quota']['used']);
        $this->assertSame(1, $mine['upcoming_reservations_count']);

        // Organisation imbriquée dans une réservation : pas de quota (pas de requête par ligne)
        $nested = $this->getJson('/api/reservations')->assertOk()->json('data.0.organization');
        $this->assertArrayNotHasKey('quota', $nested);
    }

    public function test_the_quota_set_by_an_agent_is_the_one_that_blocks_in_both_directions(): void
    {
        $asbl = $this->asbl();
        $quota = app(ReservationQuotaService::class);
        // Déjà passées cette année : elles comptent dans le quota annuel, pas dans la limite des confirmées à venir
        foreach (['2026-01-12', '2026-02-09', '2026-03-09', '2026-04-13', '2026-05-11', '2026-05-18'] as $day) {
            $this->reservation($asbl, ['trip_date' => $day, 'status' => ReservationStatus::CONFIRME]);
        }

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->patchJson("/api/organizations/{$asbl->id}", ['annual_quota' => 8])
            ->assertOk()
            ->assertJsonPath('data.quota.limit', 8)
            ->assertJsonPath('data.quota.used', 6)
            ->assertJsonPath('data.quota.remaining', 2);
        $this->assertNull($this->ensureCanCreate($quota, $asbl->refresh())); // 6 sur 8 : au-dessus de l'ancien plafond de 5

        $this->patchJson("/api/organizations/{$asbl->id}", ['annual_quota' => 0])->assertOk()->assertJsonPath('data.quota.remaining', 0);
        $this->assertStringContainsString('quota annuel', $this->ensureCanCreate($quota, $asbl->refresh()));
    }

    public function test_update_is_traced_and_the_proof_document_must_exist(): void
    {
        $asbl = $this->asbl();
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->patchJson("/api/organizations/{$asbl->id}", ['proof_document_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof_document_id');

        $this->patchJson("/api/organizations/{$asbl->id}", ['type' => 'externe', 'phone_contact' => '081 22 33 44'])
            ->assertOk()
            ->assertJsonPath('data.type', 'externe')
            ->assertJsonPath('data.proof_document_id', null)
            ->assertJsonMissingPath('data.document_proof_path');

        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'organization',
            'aggregate_id' => $asbl->id,
            'event_type' => 'organization.updated',
        ]);
    }

    public function test_ban_without_cancelling_keeps_upcoming_reservations_and_warns_members(): void
    {
        $asbl = $this->asbl();
        $upcoming = $this->reservation($asbl, ['trip_date' => '2026-06-20', 'status' => ReservationStatus::APPROUVE]);

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->postJson("/api/organizations/{$asbl->id}/ban", ['reason' => 'Test'])->assertForbidden(); // admin seulement

        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));
        $this->postJson("/api/organizations/{$asbl->id}/ban", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/organizations/{$asbl->id}/ban", ['reason' => 'Dégradations dans le bus.'])
            ->assertOk()
            ->assertJsonPath('data.is_banned', true)
            ->assertJsonPath('meta.cancelled_reservation_ids', []);

        $this->assertSame(ReservationStatus::APPROUVE, $upcoming->refresh()->status);
        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $this->user('responsable.test@bus-communal.local')->id,
            'event_key' => 'organization.banned',
            'channel' => 'database',
        ]);
        $this->assertDatabaseHas('domain_events', ['aggregate_id' => $asbl->id, 'event_type' => 'organization.banned']);
        $this->postJson("/api/organizations/{$asbl->id}/ban", ['reason' => 'Encore'])->assertUnprocessable();

        $this->postJson("/api/organizations/{$asbl->id}/unban")->assertOk()->assertJsonPath('data.is_banned', false);
        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $this->user('responsable.test@bus-communal.local')->id,
            'event_key' => 'organization.unbanned',
        ]);
    }

    public function test_ban_can_cancel_all_upcoming_reservations(): void
    {
        $asbl = $this->asbl();
        $first = $this->reservation($asbl, ['trip_date' => '2026-06-20', 'status' => ReservationStatus::APPROUVE]);
        $second = $this->reservation($asbl, ['trip_date' => '2026-07-02', 'status' => ReservationStatus::EN_ATTENTE]);
        $past = $this->reservation($asbl, ['trip_date' => '2026-03-02', 'status' => ReservationStatus::CONFIRME]);

        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));
        $ids = $this->postJson("/api/organizations/{$asbl->id}/ban", ['reason' => 'Dégradations dans le bus.', 'cancel_upcoming' => true])
            ->assertOk()
            ->assertJsonPath('data.upcoming_reservations_count', 0)
            ->json('meta.cancelled_reservation_ids');

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
        $this->assertSame(ReservationStatus::ANNULE, $first->refresh()->status);
        $this->assertStringContainsString('Organisation bannie : Dégradations', $first->cancellation_reason);
        $this->assertSame(ReservationStatus::CONFIRME, $past->refresh()->status); // le passé n'est pas touché
    }

    private function ensureCanCreate(ReservationQuotaService $quota, Organization $organization): ?string
    {
        try {
            $quota->ensureCanCreate($organization, '2026-09-15');

            return null;
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->implode(' ');
        }
    }

    private function asbl(): Organization
    {
        return Organization::query()->where('name', 'ASBL Les Amis du Parc')->firstOrFail();
    }

    private function reservation(Organization $organization, array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('responsable.test@bus-communal.local')->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'trip_date' => '2026-06-20',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => ApprovalType::MANUEL,
            ...$attributes,
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
