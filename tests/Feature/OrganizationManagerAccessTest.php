<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Droits de responsable portés par l'appartenance à chaque organisation
 * (relationship_type responsable/directeur), et non plus par un rôle global.
 */
class OrganizationManagerAccessTest extends TestCase
{
    use DatabaseTransactions;

    private Organization $organization;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class, TestLocationsSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->organization = Organization::create([
            'name' => 'ASBL Droits',
            'type' => OrganizationType::ASBL,
            'official_email' => 'droits@asbl.test',
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);

        $requester = $this->member('prof');
        [$pickup, $destination] = Location::query()->limit(2)->get()->all();

        $this->reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $this->organization->id,
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'trip_date' => now()->addDays(30)->toDateString(),
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'activity_type' => ReservationActivityType::STANDARD,
            'estimated_duration_min' => 30,
            'status' => ReservationStatus::EN_ATTENTE,
            'approval_type' => 'manuel',
        ]);
    }

    public function test_simple_member_can_view_but_not_manage_another_members_reservation(): void
    {
        Sanctum::actingAs($this->member('prof'));

        $this->getJson("/api/reservations/{$this->reservation->id}")->assertOk();
        $this->patchJson("/api/reservations/{$this->reservation->id}/cancel", ['reason' => 'Test'])->assertForbidden();
        $this->patchJson("/api/reservations/{$this->reservation->id}", ['trip_purpose' => 'Autre'])->assertForbidden();
    }

    public function test_member_listing_includes_organization_reservations(): void
    {
        Sanctum::actingAs($this->member('animateur'));

        $this->getJson('/api/reservations')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->reservation->id]);
    }

    public function test_manager_of_the_organization_can_cancel(): void
    {
        foreach (['responsable', 'directeur'] as $type) {
            $this->assertTrue($this->member($type)->managesOrganization($this->organization));
        }

        Sanctum::actingAs($this->member('responsable'));

        $this->patchJson("/api/reservations/{$this->reservation->id}/cancel", ['reason' => 'Sortie annulée'])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::ANNULE->value);
    }

    public function test_manager_of_another_organization_gets_no_rights_here(): void
    {
        $other = Organization::create([
            'name' => 'ASBL Voisine',
            'type' => OrganizationType::ASBL,
            'official_email' => 'voisine@asbl.test',
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);

        // Responsable ailleurs, simple prof ici : c'était la faille du rôle global.
        $user = $this->member('prof');
        $user->organizations()->attach($other->id, ['relationship_type' => 'responsable', 'is_default' => false]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/reservations/{$this->reservation->id}/cancel", ['reason' => 'Test'])->assertForbidden();
    }

    public function test_non_member_cannot_view_the_reservation(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail());

        $this->getJson("/api/reservations/{$this->reservation->id}")->assertForbidden();
    }

    public function test_seeder_migrates_legacy_responsable_role_to_user(): void
    {
        $legacy = Role::findOrCreate('responsable_organisation', 'web');
        $user = User::factory()->create();
        $user->assignRole($legacy);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertNull(Role::query()->where('name', 'responsable_organisation')->first());
        $this->assertTrue($user->fresh()->hasRole('user'));
    }

    public function test_seeder_removes_permissions_that_are_not_in_the_seeder(): void
    {
        Role::findByName('agent_communal', 'web')->givePermissionTo('users.deactivate');

        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(Role::findByName('agent_communal', 'web')->hasPermissionTo('users.deactivate'));
    }

    private function member(string $relationshipType): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->organizations()->attach($this->organization->id, [
            'relationship_type' => $relationshipType,
            'is_default' => true,
        ]);

        return $user;
    }
}
