<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrganizationMemberApiTest extends TestCase
{
    use DatabaseTransactions;

    private Organization $organization;

    private User $manager;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->organization = $this->makeOrganization('ASBL Membres');
        $this->manager = $this->attach($this->organization, 'responsable');
        $this->member = $this->attach($this->organization, 'prof');
    }

    public function test_members_can_list_members_but_outsiders_cannot(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson("/api/organizations/{$this->organization->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['user_id' => $this->manager->id, 'relationship_type' => 'responsable', 'is_manager' => true]);

        Sanctum::actingAs($this->seeded('user.test@bus-communal.local'));

        $this->getJson("/api/organizations/{$this->organization->id}/members")->assertForbidden();
    }

    public function test_manager_can_change_member_type_and_simple_member_cannot(): void
    {
        Sanctum::actingAs($this->member);
        $this->patchJson($this->memberUrl($this->manager), ['relationship_type' => 'prof'])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->patchJson($this->memberUrl($this->member), ['relationship_type' => 'directeur'])
            ->assertOk()
            ->assertJsonPath('data.is_manager', true);

        $this->assertTrue($this->member->fresh()->managesOrganization($this->organization));
    }

    public function test_last_manager_cannot_be_demoted_removed_or_leave(): void
    {
        Sanctum::actingAs($this->manager);

        $this->patchJson($this->memberUrl($this->manager), ['relationship_type' => 'prof'])
            ->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->deleteJson($this->memberUrl($this->manager))->assertUnprocessable();
        $this->postJson("/api/organizations/{$this->organization->id}/leave")->assertUnprocessable();

        $this->assertTrue($this->manager->fresh()->managesOrganization($this->organization));
    }

    public function test_manager_can_remove_a_member(): void
    {
        Sanctum::actingAs($this->manager);

        $this->deleteJson($this->memberUrl($this->member))->assertNoContent();

        $this->assertFalse($this->member->fresh()->isMemberOf($this->organization));
    }

    public function test_agent_can_manage_members_of_any_organization(): void
    {
        Sanctum::actingAs($this->seeded('agent.test@bus-communal.local'));

        $this->patchJson($this->memberUrl($this->member), ['relationship_type' => 'animateur'])->assertOk();
    }

    public function test_member_can_leave_and_default_moves_to_remaining_organization(): void
    {
        $other = $this->makeOrganization('ASBL Autre');
        $this->member->organizations()->attach($other->id, ['relationship_type' => 'membre', 'is_default' => false]);

        Sanctum::actingAs($this->member);

        $this->postJson("/api/organizations/{$this->organization->id}/leave")->assertNoContent();

        $remaining = $this->member->fresh()->organizations()->sole();
        $this->assertSame($other->id, $remaining->id);
        $this->assertTrue((bool) $remaining->pivot->is_default);
    }

    public function test_member_can_choose_default_organization(): void
    {
        $other = $this->makeOrganization('ASBL Défaut');
        $this->member->organizations()->attach($other->id, ['relationship_type' => 'membre', 'is_default' => false]);

        Sanctum::actingAs($this->member);

        $this->patchJson("/api/organizations/{$other->id}/default")->assertOk();

        $defaults = $this->member->fresh()->organizations()->wherePivot('is_default', true)->pluck('organizations.id')->all();
        $this->assertSame([$other->id], $defaults);
    }

    public function test_invalid_relationship_type_is_rejected(): void
    {
        Sanctum::actingAs($this->manager);

        $this->patchJson($this->memberUrl($this->member), ['relationship_type' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('relationship_type');
    }

    private function memberUrl(User $user): string
    {
        return "/api/organizations/{$this->organization->id}/members/{$user->id}";
    }

    private function makeOrganization(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'type' => OrganizationType::ASBL,
            'official_email' => str($name)->slug().'@asbl.test',
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);
    }

    private function attach(Organization $organization, string $type): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->organizations()->attach($organization->id, ['relationship_type' => $type, 'is_default' => true]);

        return $user;
    }

    private function seeded(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
