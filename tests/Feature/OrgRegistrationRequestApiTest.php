<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrgRegistrationRequestApiTest extends TestCase
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
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_cannot_access_org_registration_request_routes(): void
    {
        $this->getJson('/api/org-registration-requests')->assertUnauthorized();
        $this->postJson('/api/org-registration-requests', [])->assertUnauthorized();
    }

    public function test_user_can_create_org_registration_request(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $location = $this->seededLocation();

        $this->postJson('/api/org-registration-requests', [
            'organization_name' => 'ASBL Les Amis du Parc',
            'organization_type' => OrganizationType::ASBL->value,
            'enterprise_number' => '0123.456.789',
            'official_email' => 'contact@amisduparc.test',
            'phone' => '082000000',
            'location_id' => $location->id,
            'contact_person_name' => 'Alice Martin',
            'contact_person_role' => 'Responsable',
            'comment' => 'Demande de creation',
        ])
            ->assertCreated()
            ->assertJsonPath('data.request_by_user_id', $user->id)
            ->assertJsonPath('data.organization_name', 'ASBL Les Amis du Parc')
            ->assertJsonPath('data.status', RequestStatus::EN_ATTENTE->value)
            ->assertJsonPath('data.confidence_note', 81)
            ->assertJsonPath('data.confidence_details.score', 81);

        $this->assertDatabaseHas('org_registration_requests', [
            'request_by_user_id' => $user->id,
            'organization_name' => 'ASBL Les Amis du Parc',
            'status' => RequestStatus::EN_ATTENTE->value,
            'confidence_note' => 81,
        ]);
    }

    public function test_confidence_score_rewards_verified_location_in_configured_municipality(): void
    {
        config([
            'municipality.name' => 'Dinant',
            'municipality.localities' => ['Dinant', 'Anseremme'],
        ]);

        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $location = $this->seededLocation();

        $this->postJson('/api/org-registration-requests', [
            'organization_name' => 'ASBL Dinant Solidaire',
            'organization_type' => OrganizationType::ASBL->value,
            'enterprise_number' => '0123.456.789',
            'official_email' => 'contact@dinant-solidaire.test',
            'phone' => '082000000',
            'location_id' => $location->id,
            'contact_person_name' => 'Alice Martin',
            'contact_person_role' => 'Responsable',
            'proof_document_path' => 'proofs/asbl-dinant.pdf',
        ])
            ->assertCreated()
            ->assertJsonPath('data.confidence_note', 96)
            ->assertJsonPath('data.confidence_details.score', 96)
            ->assertJsonFragment([
                'Adresse situee dans la commune de Dinant.',
            ]);
    }

    public function test_create_org_registration_request_rejects_invalid_type(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $this->postJson('/api/org-registration-requests', [
            'organization_name' => 'Organisation invalide',
            'organization_type' => 'invalid',
            'official_email' => 'contact@example.test',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('organization_type');
    }

    public function test_user_without_view_permission_cannot_list_requests(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/org-registration-requests')->assertForbidden();
    }

    public function test_user_with_view_permission_only_sees_their_own_registration_requests(): void
    {
        $role = Role::findOrCreate('org_request_reader', 'web');
        $role->givePermissionTo([
            'org-registration-requests.view',
            'org-registration-requests.create',
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);
        $otherUser = User::factory()->create();

        $ownRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $user->id,
            'organization_name' => 'Ma demande',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'mine@example.test',
            'status' => RequestStatus::EN_ATTENTE,
        ]);
        $otherRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $otherUser->id,
            'organization_name' => 'Demande cachee',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'other@example.test',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/org-registration-requests')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownRequest->id,
            ])
            ->assertJsonMissing([
                'id' => $otherRequest->id,
            ]);

        $this->getJson("/api/org-registration-requests/{$ownRequest->id}")
            ->assertOk();

        $this->getJson("/api/org-registration-requests/{$otherRequest->id}")
            ->assertForbidden();
    }

    public function test_agent_can_list_and_show_registration_requests(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $registrationRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Ecole communale',
            'organization_type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'ecole@example.test',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->getJson('/api/org-registration-requests')
            ->assertOk()
            ->assertJsonPath('data.0.organization_name', 'Ecole communale');

        $this->getJson("/api/org-registration-requests/{$registrationRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $registrationRequest->id);
    }

    public function test_agent_lists_registration_requests_by_confidence_score(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();

        OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Demande faible',
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => 'faible@example.test',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 20,
        ]);

        OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Demande forte',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'forte@example.test',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 80,
        ]);

        $this->getJson('/api/org-registration-requests')
            ->assertOk()
            ->assertJsonPath('data.0.organization_name', 'Demande forte')
            ->assertJsonPath('data.1.organization_name', 'Demande faible');
    }

    public function test_agent_can_approve_registration_request_and_create_organization(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $registrationRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Ecole communale de Dinant',
            'organization_type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'direction@example.test',
            'phone' => '082000000',
            'contact_person_name' => 'Alice Martin',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/org-registration-requests/{$registrationRequest->id}/approve")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ecole communale de Dinant')
            ->assertJsonPath('data.type', OrganizationType::ECOLE_COMMUNALE->value)
            ->assertJsonPath('data.is_priority', true);

        $organization = Organization::where('name', 'Ecole communale de Dinant')->firstOrFail();

        $this->assertTrue($requester->fresh()->organizations()->whereKey($organization->id)->exists());
        $this->assertDatabaseHas('org_registration_requests', [
            'id' => $registrationRequest->id,
            'status' => RequestStatus::APPROUVE->value,
            'reviewed_by_user_id' => $agent->id,
        ]);
    }

    public function test_agent_can_reject_registration_request(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $registrationRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Organisation incomplete',
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => 'contact@example.test',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/org-registration-requests/{$registrationRequest->id}/reject", [
            'rejection_reason' => 'Document justificatif manquant.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::REFUSE->value)
            ->assertJsonPath('data.rejection_reason', 'Document justificatif manquant.');

        $this->assertDatabaseMissing('organizations', [
            'name' => 'Organisation incomplete',
        ]);
    }

    public function test_processed_registration_request_cannot_be_reviewed_twice(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create();
        $registrationRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'Organisation deja traitee',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'contact@example.test',
            'status' => RequestStatus::REFUSE,
        ]);

        $this->patchJson("/api/org-registration-requests/{$registrationRequest->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function seededLocation(): Location
    {
        return Location::query()->where('place_id', 'seed-dinant-centre')->firstOrFail();
    }
}
