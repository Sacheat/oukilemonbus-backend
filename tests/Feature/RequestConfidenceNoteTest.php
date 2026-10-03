<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Services\OrgRegistrationRequests\OrgRegistrationConfidenceScoreService;
use App\Services\UserOrgRequests\UserOrgRequestConfidenceScoreService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `confidence_note` (qui trie les files de demandes) et `confidence_details.score` disent la même chose pour une demande
 * en attente ; une demande traitée garde la note de sa décision.
 */
class RequestConfidenceNoteTest extends TestCase
{
    use DatabaseTransactions;

    private User $requester;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Storage::fake('local');
        Notification::fake();

        $this->requester = User::factory()->create();
        $this->requester->assignRole('user');
        $this->agent = User::factory()->create();
        $this->agent->assignRole('agent_communal');
    }

    public function test_org_registration_note_follows_the_proof_added_after_creation(): void
    {
        Sanctum::actingAs($this->requester);
        $id = $this->postJson('/api/org-registration-requests', [
            'organization_name' => 'ASBL Les Marcheurs',
            'organization_type' => 'asbl',
            'official_email' => 'marcheurs@example.test',
            'enterprise_number' => '0123.456.749',
        ])->assertCreated()->json('data.id');
        $withoutProof = OrgRegistrationRequest::findOrFail($id)->confidence_note;

        // La SPA envoie le justificatif après la création : la note le compte (+25).
        $this->postJson("/api/org-registration-requests/{$id}/proof", ['proof_document' => $this->pdf()])->assertCreated();
        $this->assertSame($withoutProof + 25, OrgRegistrationRequest::findOrFail($id)->confidence_note);
        $this->assertNoteMatchesDetails("/api/org-registration-requests/{$id}", $withoutProof + 25);

        $this->deleteJson("/api/org-registration-requests/{$id}/proof")->assertNoContent();
        $this->assertSame($withoutProof, OrgRegistrationRequest::findOrFail($id)->confidence_note);
        $this->assertNoteMatchesDetails("/api/org-registration-requests/{$id}", $withoutProof);
    }

    public function test_user_org_request_note_follows_the_proof_added_after_creation(): void
    {
        $organization = $this->organization();
        Sanctum::actingAs($this->requester);
        $id = $this->postJson('/api/user-org-requests', [
            'organization_id' => $organization->id,
            'requested_role' => 'prof',
            'justification' => 'Je suis professeur dans cette école depuis trois ans.',
        ])->assertCreated()->json('data.request_id');
        $withoutProof = UserOrgRequest::findOrFail($id)->confidence_note;

        $this->postJson("/api/user-org-requests/{$id}/proof", ['proof_document' => $this->pdf()])->assertCreated();
        $this->assertSame($withoutProof + 25, UserOrgRequest::findOrFail($id)->confidence_note);
        $this->assertNoteMatchesDetails("/api/user-org-requests/{$id}", $withoutProof + 25);

        $this->deleteJson("/api/user-org-requests/{$id}/proof")->assertNoContent();
        $this->assertSame($withoutProof, UserOrgRequest::findOrFail($id)->confidence_note);
        $this->assertNoteMatchesDetails("/api/user-org-requests/{$id}", $withoutProof);
    }

    public function test_a_pending_request_always_shows_the_current_score_in_list_and_detail(): void
    {
        $organization = $this->organization();
        $adhesion = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $this->requester->id,
            'requested_role' => 'prof',
            'justification' => 'Je suis professeur dans cette école depuis trois ans.',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 5, // note enregistrée périmée
        ]);
        $registration = OrgRegistrationRequest::create([
            'request_by_user_id' => $this->requester->id,
            'organization_name' => 'Club des Sports',
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => 'sports@example.test',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 96, // idem
        ]);
        $adhesionLive = app(UserOrgRequestConfidenceScoreService::class)->calculate($adhesion);
        $registrationLive = app(OrgRegistrationConfidenceScoreService::class)->calculate($registration);
        $this->assertNotSame(5, $adhesionLive);
        $this->assertNotSame(96, $registrationLive);

        Sanctum::actingAs($this->agent);
        $this->assertNoteMatchesDetails("/api/user-org-requests/{$adhesion->request_id}", $adhesionLive);
        $this->assertNoteMatchesDetails("/api/org-registration-requests/{$registration->id}", $registrationLive);

        $this->getJson('/api/user-org-requests?statuses[]=en_attente')
            ->assertJsonPath('data.0.confidence_note', $adhesionLive)
            ->assertJsonPath('data.0.confidence_details.score', $adhesionLive);
        $this->getJson('/api/org-registration-requests?statuses[]=en_attente')
            ->assertJsonPath('data.0.confidence_note', $registrationLive)
            ->assertJsonPath('data.0.confidence_details.score', $registrationLive);
    }

    public function test_user_org_decision_freezes_the_score_the_agent_saw_unless_they_give_a_note(): void
    {
        $organization = $this->organization();
        $first = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $this->requester->id,
            'requested_role' => 'prof',
            'justification' => 'Je suis professeur dans cette école depuis trois ans.',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 5,
        ]);
        $expected = app(UserOrgRequestConfidenceScoreService::class)->calculate($first);

        Sanctum::actingAs($this->agent);
        $this->patchJson("/api/user-org-requests/{$first->request_id}/approve")
            ->assertOk()
            ->assertJsonPath('data.confidence_note', $expected);
        $this->assertSame($expected, $first->fresh()->confidence_note);

        $other = User::factory()->create();
        $second = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $other->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 5,
        ]);
        $this->patchJson("/api/user-org-requests/{$second->request_id}/reject", ['rejection_reason' => 'Non.', 'confidence_note' => 33])
            ->assertOk()
            ->assertJsonPath('data.confidence_note', 33);
    }

    public function test_org_registration_refusal_freezes_the_score_from_before_the_proof_is_deleted(): void
    {
        Sanctum::actingAs($this->requester);
        $id = $this->postJson('/api/org-registration-requests', [
            'organization_name' => 'ASBL Les Marcheurs',
            'organization_type' => 'asbl',
            'official_email' => 'marcheurs@example.test',
            'enterprise_number' => '0123.456.749',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/org-registration-requests/{$id}/proof", ['proof_document' => $this->pdf()])->assertCreated();
        $beforeDecision = OrgRegistrationRequest::findOrFail($id)->confidence_note;

        Sanctum::actingAs($this->agent);
        $this->patchJson("/api/org-registration-requests/{$id}/reject", ['rejection_reason' => 'Dossier incomplet.'])
            ->assertOk()
            ->assertJsonPath('data.confidence_note', $beforeDecision);

        // Le refus supprime le justificatif : le détail recalculé est plus bas, la note de la décision ne bouge pas.
        $this->getJson("/api/org-registration-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'refuse')
            ->assertJsonPath('data.confidence_note', $beforeDecision)
            ->assertJsonPath('data.confidence_details.score', $beforeDecision - 25);
    }

    public function test_org_registration_approval_does_not_lose_points_for_the_organization_it_creates(): void
    {
        $registration = OrgRegistrationRequest::create([
            'request_by_user_id' => $this->requester->id,
            'organization_name' => 'ASBL Les Marcheurs',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'marcheurs@example.test',
            'enterprise_number' => '0123.456.749',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 1,
        ]);
        $expected = app(OrgRegistrationConfidenceScoreService::class)->calculate($registration);

        Sanctum::actingAs($this->agent);
        $this->patchJson("/api/org-registration-requests/{$registration->id}/approve")->assertCreated();

        $this->assertSame($expected, $registration->fresh()->confidence_note);
        // L'organisation créée porte le même email : ce n'est pas un avertissement pour une demande traitée.
        $warnings = $this->getJson("/api/org-registration-requests/{$registration->id}")->assertOk()->json('data.confidence_details.warnings');
        $this->assertNotContains('Email officiel déjà utilisé par une organisation existante.', $warnings);
    }

    public function test_a_processed_request_keeps_its_decision_note(): void
    {
        $registration = OrgRegistrationRequest::create([
            'request_by_user_id' => $this->requester->id,
            'organization_name' => 'Club décidé',
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => 'decide@example.test',
            'status' => RequestStatus::REFUSE,
            'confidence_note' => 96,
        ]);

        Sanctum::actingAs($this->agent);
        $this->getJson("/api/org-registration-requests/{$registration->id}")->assertJsonPath('data.confidence_note', 96);
        $this->getJson('/api/org-registration-requests?statuses[]=refuse')->assertJsonPath('data.0.confidence_note', 96);
        $this->assertSame(96, $registration->fresh()->confidence_note);
    }

    public function test_refresh_command_resyncs_pending_notes_only(): void
    {
        $organization = $this->organization();
        $adhesion = UserOrgRequest::create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $this->requester->id,
            'requested_role' => 'prof',
            'justification' => 'Je suis professeur dans cette école depuis trois ans.',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 5,
        ]);
        $registration = OrgRegistrationRequest::create([
            'request_by_user_id' => $this->requester->id,
            'organization_name' => 'Club des Sports',
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => 'sports@example.test',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 96,
        ]);
        $refused = OrgRegistrationRequest::create([
            'request_by_user_id' => $this->requester->id,
            'organization_name' => 'Club refusé',
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => 'refuse@example.test',
            'status' => RequestStatus::REFUSE,
            'confidence_note' => 96,
        ]);

        $this->artisan('requests:refresh-confidence-notes')
            ->expectsOutputToContain('2 demande(s) en attente vérifiée(s), 2 note(s) mise(s) à jour.')
            ->assertSuccessful();

        $this->assertSame(app(UserOrgRequestConfidenceScoreService::class)->calculate($adhesion), $adhesion->fresh()->confidence_note);
        $this->assertSame(app(OrgRegistrationConfidenceScoreService::class)->calculate($registration), $registration->fresh()->confidence_note);
        $this->assertSame(96, $refused->fresh()->confidence_note);

        // Seconde exécution : rien à rattraper.
        $this->artisan('requests:refresh-confidence-notes')
            ->expectsOutputToContain('2 demande(s) en attente vérifiée(s), 0 note(s) mise(s) à jour.')
            ->assertSuccessful();
    }

    private function assertNoteMatchesDetails(string $url, int $expected): void
    {
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.confidence_note', $expected)
            ->assertJsonPath('data.confidence_details.score', $expected);
    }

    private function organization(): Organization
    {
        return Organization::create(['name' => 'École des Fagnes', 'type' => OrganizationType::ECOLE_COMMUNALE, 'official_email' => 'fagnes@example.test']);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('justificatif.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }
}
