<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserOrgRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserOrgRequestApiTest extends TestCase
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

    public function test_guest_cannot_access_user_org_request_routes(): void
    {
        $this->getJson('/api/user-org-requests')->assertUnauthorized();
        $this->postJson('/api/user-org-requests', [])->assertUnauthorized();
    }

    public function test_user_can_request_to_join_existing_organization(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $organization = $this->seededOrganization();

        $this->postJson('/api/user-org-requests', [
            'organization_id' => $organization->id,
            'requested_role' => 'animateur',
            'justification' => 'Je participe aux activites.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.requested_by_user_id', $user->id)
            ->assertJsonPath('data.requested_role', 'animateur')
            ->assertJsonPath('data.status', RequestStatus::EN_ATTENTE->value)
            ->assertJsonPath('data.confidence_note', 62)
            ->assertJsonPath('data.confidence_details.score', 62);

        $this->assertDatabaseHas('user_org_requests', [
            'organization_id' => $organization->id,
            'requested_by_user_id' => $user->id,
            'requested_role' => 'animateur',
            'status' => RequestStatus::EN_ATTENTE->value,
            'confidence_note' => 62,
        ]);
    }

    public function test_confidence_score_does_not_use_organization_address(): void
    {
        config([
            'municipality.name' => 'Dinant',
            'municipality.localities' => ['Dinant', 'Anseremme'],
        ]);

        $user = User::factory()->create(['phone' => '082000000']);
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $location = $this->seededLocation();
        $organization = $this->seededOrganization();
        $organization->update([
            'phone_contact' => '082111111',
            'name_contact' => 'Alice Martin',
            'location_id' => $location->id,
            'document_proof_path' => 'proofs/asbl.pdf',
        ]);

        $this->postJson('/api/user-org-requests', [
            'organization_id' => $organization->id,
            'requested_role' => 'animateur',
            'justification' => 'Je suis responsable des activites hebdomadaires.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.confidence_note', 75)
            ->assertJsonPath('data.confidence_details.score', 75)
            ->assertJsonMissing([
                'Organisation situee dans la commune de Dinant.',
            ])
            ->assertJsonMissing([
                'Adresse de l organisation verifiee.',
            ]);
    }

    public function test_complete_user_org_request_reaches_max_confidence_score(): void
    {
        $user = User::factory()->create(['phone' => '082000000']);
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $organization = $this->seededOrganization();

        $this->postJson('/api/user-org-requests', [
            'organization_id' => $organization->id,
            'requested_role' => 'responsable',
            'justification' => 'Je suis responsable de la coordination des activites de cette organisation.',
            'proof_document_path' => 'proofs/rattachement.pdf',
        ])
            ->assertCreated()
            ->assertJsonPath('data.confidence_note', 100)
            ->assertJsonPath('data.confidence_details.score', 100)
            ->assertJsonFragment([
                'Justification coherente avec le role demande.',
            ]);
    }

    public function test_user_cannot_request_to_join_same_organization_twice_while_pending(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $organization = $this->seededOrganization();

        UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $user->id,
            'requested_role' => 'animateur',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->postJson('/api/user-org-requests', [
            'organization_id' => $organization->id,
            'requested_role' => 'animateur',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');
    }

    public function test_user_cannot_request_to_join_organization_when_already_member(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $organization = $this->seededOrganization();
        $organization->users()->attach($user->id, [
            'relationship_type' => 'responsable',
            'is_default' => true,
        ]);

        $this->postJson('/api/user-org-requests', [
            'organization_id' => $organization->id,
            'requested_role' => 'animateur',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');
    }

    public function test_create_user_org_request_rejects_invalid_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $organization = $this->seededOrganization();

        $this->postJson('/api/user-org-requests', [
            'organization_id' => $organization->id,
            'requested_role' => 'intrus',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requested_role');
    }

    public function test_user_without_view_permission_cannot_list_user_org_requests(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user-org-requests')->assertForbidden();
    }

    public function test_user_with_view_permission_only_sees_their_own_user_org_requests(): void
    {
        $role = Role::findOrCreate('user_org_request_reader', 'web');
        $role->givePermissionTo([
            'user-org-requests.view',
            'user-org-requests.create',
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);
        $otherUser = User::factory()->create();
        $organization = $this->seededOrganization();

        $ownRequest = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $user->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
        ]);
        $otherRequest = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $otherUser->id,
            'requested_role' => 'animateur',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user-org-requests')
            ->assertOk()
            ->assertJsonFragment([
                'request_id' => $ownRequest->request_id,
            ])
            ->assertJsonMissing([
                'request_id' => $otherRequest->request_id,
            ]);

        $this->getJson("/api/user-org-requests/{$ownRequest->request_id}")
            ->assertOk();

        $this->getJson("/api/user-org-requests/{$otherRequest->request_id}")
            ->assertForbidden();
    }

    public function test_agent_can_list_and_show_user_org_requests(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $organization = $this->seededOrganization();
        $request = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->getJson('/api/user-org-requests')
            ->assertOk()
            ->assertJsonPath('data.0.requested_role', 'prof');

        $this->getJson("/api/user-org-requests/{$request->request_id}")
            ->assertOk()
            ->assertJsonPath('data.request_id', $request->request_id);
    }

    public function test_agent_lists_user_org_requests_by_confidence_score(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $organization = $this->seededOrganization();

        UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 20,
        ]);

        UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'animateur',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 80,
        ]);

        $this->getJson('/api/user-org-requests')
            ->assertOk()
            ->assertJsonPath('data.0.requested_role', 'animateur')
            ->assertJsonPath('data.1.requested_role', 'prof');
    }

    public function test_agent_can_approve_user_org_request_and_attach_user_to_organization(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $organization = $this->seededOrganization();
        $request = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'directeur',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/user-org-requests/{$request->request_id}/approve", [
            'confidence_note' => 90,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::APPROUVE->value)
            ->assertJsonPath('data.confidence_note', 90)
            ->assertJsonPath('data.reviewed_by_user_id', $agent->id);

        $this->assertDatabaseHas('user_organization', [
            'user_id' => $requester->id,
            'organization_id' => $organization->id,
            'relationship_type' => 'directeur',
            'is_default' => true,
        ]);
    }

    public function test_approved_user_org_request_is_not_default_when_user_already_has_default_organization(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $firstOrganization = $this->createOrganization('Premiere ASBL');
        $secondOrganization = $this->createOrganization('Deuxieme ASBL');
        $firstOrganization->users()->attach($requester->id, [
            'relationship_type' => 'responsable',
            'is_default' => true,
        ]);

        $request = UserOrgRequest::create([
            'organization_id' => $secondOrganization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/user-org-requests/{$request->request_id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::APPROUVE->value);

        $this->assertDatabaseHas('user_organization', [
            'user_id' => $requester->id,
            'organization_id' => $secondOrganization->id,
            'relationship_type' => 'prof',
            'is_default' => false,
        ]);
    }

    public function test_agent_can_reject_user_org_request(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $organization = $this->createOrganization();
        $request = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/user-org-requests/{$request->request_id}/reject", [
            'rejection_reason' => 'Justificatif insuffisant.',
            'confidence_note' => 20,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::REFUSE->value)
            ->assertJsonPath('data.rejection_reason', 'Justificatif insuffisant.')
            ->assertJsonPath('data.confidence_note', 20);

        $this->assertDatabaseMissing('user_organization', [
            'user_id' => $requester->id,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_processed_user_org_request_cannot_be_reviewed_twice(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $organization = $this->createOrganization();
        $request = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::REFUSE,
        ]);

        $this->patchJson("/api/user-org-requests/{$request->request_id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function createOrganization(string $name = 'ASBL Les Amis du Parc'): Organization
    {
        return Organization::create([
            'name' => $name,
            'type' => OrganizationType::ASBL,
            'official_email' => fake()->unique()->safeEmail(),
        ]);
    }

    private function seededOrganization(): Organization
    {
        return Organization::query()->where('name', 'ASBL Les Amis du Parc')->firstOrFail();
    }

    private function seededLocation(): Location
    {
        return Location::query()->where('place_id', 'seed-dinant-centre')->firstOrFail();
    }
}
