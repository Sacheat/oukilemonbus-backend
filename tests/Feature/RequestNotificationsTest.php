<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use App\Models\UserOrgRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RequestNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_approving_org_registration_request_notifies_requester(): void
    {
        $agent = User::factory()->create(['active' => true]);
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create(['active' => true]);
        $registrationRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'ASBL Test Approbation',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'contact@test.test',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/org-registration-requests/{$registrationRequest->id}/approve")
            ->assertCreated();

        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $requester->id,
            'event_key' => 'org_registration_request.approved',
        ]);
    }

    public function test_rejecting_org_registration_request_notifies_requester_with_reason(): void
    {
        $agent = User::factory()->create(['active' => true]);
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create(['active' => true]);
        $registrationRequest = OrgRegistrationRequest::create([
            'request_by_user_id' => $requester->id,
            'organization_name' => 'ASBL Test Refus',
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => 'contact@test.test',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/org-registration-requests/{$registrationRequest->id}/reject", [
            'rejection_reason' => 'Document justificatif manquant.',
        ])->assertOk();

        $delivery = NotificationDelivery::query()
            ->where('notifiable_id', $requester->id)
            ->where('event_key', 'org_registration_request.rejected')
            ->first();

        $this->assertNotNull($delivery);
        $this->assertStringContainsString('Document justificatif manquant.', $delivery->payload['message'] ?? '');
    }

    public function test_approving_user_org_request_notifies_requester(): void
    {
        $agent = User::factory()->create(['active' => true]);
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create(['active' => true]);
        $organization = $this->createOrganization();
        $request = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'responsable',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/user-org-requests/{$request->request_id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('notification_deliveries', [
            'notifiable_id' => $requester->id,
            'event_key' => 'user_org_request.approved',
        ]);
    }

    public function test_rejecting_user_org_request_notifies_requester_with_reason(): void
    {
        $agent = User::factory()->create(['active' => true]);
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        $requester = User::factory()->create(['active' => true]);
        $organization = $this->createOrganization();
        $request = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $requester->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
        ]);

        $this->patchJson("/api/user-org-requests/{$request->request_id}/reject", [
            'rejection_reason' => 'Justificatif insuffisant.',
        ])->assertOk();

        $delivery = NotificationDelivery::query()
            ->where('notifiable_id', $requester->id)
            ->where('event_key', 'user_org_request.rejected')
            ->first();

        $this->assertNotNull($delivery);
        $this->assertStringContainsString('Justificatif insuffisant.', $delivery->payload['message'] ?? '');
    }

    private function createOrganization(): Organization
    {
        return Organization::create([
            'name' => 'ASBL Notifications Test',
            'type' => OrganizationType::ASBL,
            'official_email' => fake()->unique()->safeEmail(),
        ]);
    }
}