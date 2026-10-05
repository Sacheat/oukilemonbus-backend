<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Services\UserOrgRequests\UserOrgRequestConfidenceScoreService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Barème du score de confiance d'une demande d'adhésion (voir UserOrgRequestConfidenceScoreService et
 * docs/organizations.md). Chaque demande de test a sa propre organisation et son propre demandeur.
 */
class UserOrgRequestConfidenceScoreTest extends TestCase
{
    use DatabaseTransactions;

    /** Une justification par rôle : détaillée (30 caractères, 5 mots) et avec un mot du vocabulaire du rôle. */
    private const array COHERENT_JUSTIFICATIONS = [
        'prof' => 'Je travaille dans cette ecole depuis longtemps.',
        'directeur' => 'Je travaille dans cet etablissement depuis longtemps.',
        'animateur' => "J'anime des ateliers pour les petits chaque semaine.",
        'responsable' => "Je m'occupe de la coordination des inscriptions du club.",
        'referent' => 'Je suis le contact pour le suivi du planning.',
    ];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_complete_coherent_request_reaches_100_for_every_role(): void
    {
        foreach (self::COHERENT_JUSTIFICATIONS as $role => $justification) {
            $request = $this->makeRequest([
                'requested_role' => $role,
                'justification' => $justification,
                'proof_document_id' => $this->proof()->id,
            ], phone: '082000000');

            $detail = $this->explain($request);
            $this->assertSame(100, $detail['score'], $role.' : '.json_encode($detail));
            $this->assertSame([], $detail['warnings'], $role);
        }
    }

    public function test_each_criterion_has_its_weight(): void
    {
        $short = 'Je viens aider le samedi'; // 24 caractères, 5 mots, aucun mot du vocabulaire
        $detailed = 'Je viens aider tous les samedis au club'; // 39 caractères, 8 mots

        $this->assertSame(
            ['score' => 0, 'reasons' => [], 'warnings' => ['Demande sans justification ni justificatif.']],
            $this->explain($this->makeRequest())
        );
        $this->assertSame(25, $this->score(['justification' => $short]));
        $this->assertSame(30, $this->score(['justification' => $short], phone: '082000000'));
        $this->assertSame(40, $this->score(['justification' => $detailed]));
        $this->assertSame(60, $this->score(['justification' => $short, 'proof_document_id' => $this->proof()->id]));
        $this->assertSame(35, $this->score(['proof_document_id' => $this->proof()->id]));
        $this->assertSame(60, $this->score(['justification' => self::COHERENT_JUSTIFICATIONS['prof']])); // 25 + 15 + 20
    }

    public function test_role_vocabulary_matches_whole_words_and_never_the_role_name(): void
    {
        // « enfantine » contient « enfant » sans être le mot : pas de cohérence.
        $partial = $this->explain($this->makeRequest([
            'requested_role' => 'animateur',
            'justification' => "Je viens de la region enfantine du pays aujourd'hui.",
        ]));
        $this->assertSame(40, $partial['score']);
        $this->assertNotContains('Justification cohérente avec le rôle demandé.', $partial['reasons']);

        // Écrire le nom du rôle ne prouve rien : 25 + 15, moins 10 (rôle sensible sans justificatif).
        $roleName = $this->explain($this->makeRequest([
            'requested_role' => 'responsable',
            'justification' => 'Je suis responsable de la boutique depuis longtemps.',
        ]));
        $this->assertSame(30, $roleName['score']);
        $this->assertNotContains('Justification cohérente avec le rôle demandé.', $roleName['reasons']);

        // Majuscules, accents et ponctuation sont ignorés ; un seul mot du vocabulaire suffit.
        $coherent = $this->explain($this->makeRequest([
            'requested_role' => 'prof',
            'justification' => 'Je donne des COURS de maths, dans cette ÉCOLE.',
        ]));
        $this->assertSame(60, $coherent['score']);
        $this->assertContains('Justification cohérente avec le rôle demandé.', $coherent['reasons']);
    }

    public function test_penalties_for_missing_or_too_short_justification(): void
    {
        // Moins de 15 caractères : −5 (25 − 5 + 20 de cohérence).
        $tooShort = $this->explain($this->makeRequest(['justification' => 'Prof école']));
        $this->assertSame(40, $tooShort['score']);
        $this->assertSame(['Justification trop courte.'], $tooShort['warnings']);

        // Au moins 15 caractères mais moins de 4 mots : avertissement, sans retrait de points (25 + 20).
        $fewWords = $this->explain($this->makeRequest(['justification' => 'Enseignant titulaire']));
        $this->assertSame(45, $fewWords['score']);
        $this->assertSame(['Justification très courte (moins de 4 mots).'], $fewWords['warnings']);

        // Ni justification ni justificatif : −15 (borné à 0).
        $nothing = $this->explain($this->makeRequest([], phone: '082000000'));
        $this->assertSame(0, $nothing['score']);
        $this->assertSame(['Demande sans justification ni justificatif.'], $nothing['warnings']);
    }

    public function test_a_sensitive_role_without_proof_loses_10_points(): void
    {
        $justification = 'Je travaille dans cette ecole depuis longtemps.'; // cohérente pour prof et directeur : 60

        $this->assertSame(60, $this->score(['requested_role' => 'prof', 'justification' => $justification]));
        $directeur = $this->explain($this->makeRequest(['requested_role' => 'directeur', 'justification' => $justification]));
        $this->assertSame(50, $directeur['score']);
        $this->assertContains('Rôle sensible sans justificatif.', $directeur['warnings']);
        $this->assertSame(95, $this->score(['requested_role' => 'directeur', 'justification' => $justification, 'proof_document_id' => $this->proof()->id]));
    }

    public function test_a_held_role_is_flagged_without_changing_the_score(): void
    {
        $organization = $this->organization();
        $holder = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Martin']);
        $organization->users()->attach($holder->id, ['relationship_type' => 'responsable', 'is_default' => true]);
        $justification = self::COHERENT_JUSTIFICATIONS['responsable'];

        $held = $this->explain($this->makeRequest(['organization_id' => $organization->id, 'requested_role' => 'responsable', 'justification' => $justification]));
        $free = $this->explain($this->makeRequest(['requested_role' => 'responsable', 'justification' => $justification]));

        $this->assertSame($free['score'], $held['score']);
        $this->assertContains("Rôle déjà tenu : l'organisation a déjà un responsable ou directeur : Alice Martin (responsable).", $held['warnings']);
        $this->assertSame([], array_filter($free['warnings'], fn (string $w): bool => str_starts_with($w, 'Rôle déjà tenu')));

        // Directeur demandé alors qu'il y a un responsable : même avertissement ; prof ou animateur : aucun.
        $directeur = $this->explain($this->makeRequest(['organization_id' => $organization->id, 'requested_role' => 'directeur', 'justification' => self::COHERENT_JUSTIFICATIONS['directeur']]));
        $this->assertStringContainsString('Rôle déjà tenu', implode(' ', $directeur['warnings']));
        $prof = $this->explain($this->makeRequest(['organization_id' => $organization->id, 'requested_role' => 'prof', 'justification' => self::COHERENT_JUSTIFICATIONS['prof']]));
        $this->assertStringNotContainsString('Rôle déjà tenu', implode(' ', $prof['warnings']));

        // Le demandeur lui-même n'est pas « l'autre » responsable.
        $self = $this->makeRequest(['organization_id' => $organization->id, 'requested_by_user_id' => $holder->id, 'requested_role' => 'responsable', 'justification' => $justification]);
        $this->assertStringNotContainsString('Rôle déjà tenu', implode(' ', $this->explain($self)['warnings']));

        // Demande traitée : l'approbation a fait du demandeur le responsable, ce n'est plus une alerte.
        $processed = $this->makeRequest(['organization_id' => $organization->id, 'requested_role' => 'responsable', 'justification' => $justification, 'status' => RequestStatus::APPROUVE]);
        $this->assertStringNotContainsString('Rôle déjà tenu', implode(' ', $this->explain($processed)['warnings']));
    }

    public function test_a_repeated_justification_is_flagged_for_pending_requests_within_90_days(): void
    {
        $requester = User::factory()->create(['phone' => null]);
        $first = $this->makeRequest(['requested_by_user_id' => $requester->id, 'justification' => 'Je travaille dans cette ecole depuis longtemps.', 'status' => RequestStatus::REFUSE]);
        $second = $this->makeRequest(['requested_by_user_id' => $requester->id, 'justification' => 'JE TRAVAILLE dans cette école, depuis longtemps !']);

        $detail = $this->explain($second);
        $this->assertContains("Même justification que la demande n°{$first->request_id} du même demandeur.", $detail['warnings']);
        $this->assertSame(60, $detail['score']); // avertissement sans retrait de points

        // Plus de 90 jours : plus une répétition.
        $first->forceFill(['created_at' => now()->subDays(100)])->save();
        $this->assertStringNotContainsString('Même justification', implode(' ', $this->explain($second)['warnings']));

        // Un autre demandeur avec le même texte, ou une demande traitée : pas d'avertissement.
        $first->forceFill(['created_at' => now()])->save();
        $other = $this->makeRequest(['justification' => 'Je travaille dans cette ecole depuis longtemps.']);
        $this->assertStringNotContainsString('Même justification', implode(' ', $this->explain($other)['warnings']));
        $second->update(['status' => RequestStatus::APPROUVE]);
        $this->assertStringNotContainsString('Même justification', implode(' ', $this->explain($second)['warnings']));
    }

    public function test_confidence_level_and_note_in_the_api(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        // En attente : niveau du score courant (60 → moyen), égal au détail.
        $pending = $this->makeRequest(['justification' => self::COHERENT_JUSTIFICATIONS['prof']]);
        $this->getJson("/api/user-org-requests/{$pending->request_id}")
            ->assertOk()
            ->assertJsonPath('data.confidence_note', 60)
            ->assertJsonPath('data.confidence_details.score', 60)
            ->assertJsonPath('data.confidence_level', 'moyen')
            ->assertJsonPath('data.confidence_level_label', 'Moyen');

        $pending->update(['proof_document_id' => $this->proof()->id]);
        $this->getJson("/api/user-org-requests/{$pending->request_id}")
            ->assertJsonPath('data.confidence_note', 95)
            ->assertJsonPath('data.confidence_level', 'eleve');

        // Traitée : niveau de la note de la décision.
        $processed = $this->makeRequest(['status' => RequestStatus::REFUSE, 'confidence_note' => 39]);
        $this->getJson('/api/user-org-requests?statuses[]=refuse')
            ->assertOk()
            ->assertJsonPath('data.0.confidence_note', 39)
            ->assertJsonPath('data.0.confidence_level', 'faible')
            ->assertJsonPath('data.0.confidence_level_label', 'Faible');
        $this->assertSame(39, $processed->fresh()->confidence_note);
    }

    private function organization(): Organization
    {
        return Organization::create([
            'name' => 'Organisation '.(++$this->sequence),
            'type' => OrganizationType::ASBL,
            'official_email' => "organisation{$this->sequence}@exemple.test",
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function makeRequest(array $attributes = [], ?string $phone = null): UserOrgRequest
    {
        return UserOrgRequest::create([
            'organization_id' => $this->organization()->id,
            'requested_by_user_id' => User::factory()->create(['phone' => $phone])->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 50,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function score(array $attributes = [], ?string $phone = null): int
    {
        return $this->explain($this->makeRequest($attributes, $phone))['score'];
    }

    private function explain(UserOrgRequest $request): array
    {
        return app(UserOrgRequestConfidenceScoreService::class)->explain($request->fresh());
    }

    private function proof(): Document
    {
        $this->sequence++;

        return Document::create([
            'document_type' => DocumentType::JUSTIFICATIF,
            'generation_mode' => 'manuel',
            'file_name' => "justificatif-{$this->sequence}.pdf",
            'file_path' => "documents/proofs/test/{$this->sequence}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', (string) $this->sequence),
        ]);
    }
}
