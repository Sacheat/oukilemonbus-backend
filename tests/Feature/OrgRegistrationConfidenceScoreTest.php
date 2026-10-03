<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use App\Services\OrgRegistrationRequests\OrgRegistrationConfidenceScoreService;
use App\Support\ConfidenceLevel;
use App\Support\EnterpriseNumber;
use App\Support\OrganizationName;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Barème du score de confiance d'une demande de création d'organisation (voir OrgRegistrationConfidenceScoreService
 * et docs/organizations.md). Chaque demande de test a son propre demandeur et un nom bien distinct des autres, pour
 * que les contrôles « autre demande en attente » et « nom presque identique » ne se déclenchent pas par accident.
 */
class OrgRegistrationConfidenceScoreTest extends TestCase
{
    use DatabaseTransactions;

    private const string VALID_BCE = '0123.456.749';

    private const string OTHER_VALID_BCE = '0202.239.951';

    /** Noms très différents les uns des autres (aucun doublon approximatif entre eux). */
    private const array NAMES = [
        'Horizon', 'Lagune', 'Cascade', 'Tilleul', 'Bruyere', 'Falaise', 'Mosaique', 'Vendange', 'Sablier', 'Cormoran',
        'Pervenche', 'Estuaire', 'Garrigue', 'Banquise', 'Oriflamme', 'Zephyr', 'Nenuphar', 'Sarcelle', 'Alizee', 'Quetsche',
    ];

    /** 30 points : de quoi éviter les pénalités de contact (le téléphone + la personne de contact valent 20). */
    private const array BASE = ['organization_type' => OrganizationType::EXTERNE, 'phone' => '082000000', 'contact_person_name' => 'Alice'];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['municipality.name' => 'Dinant', 'municipality.localities' => ['Dinant', 'Anseremme']]);
    }

    public function test_a_complete_coherent_file_reaches_100_for_every_type(): void
    {
        foreach (OrganizationType::cases() as $index => $type) {
            $request = $this->makeRequest([
                'organization_name' => self::NAMES[$index].' Solidaire',
                'organization_type' => $type,
                'official_email' => 'info@'.strtolower(self::NAMES[$index]).'.be',
                'enterprise_number' => $this->validBce(2000000 + $index),
                'phone' => '082000000',
                'contact_person_name' => 'Alice Martin',
                'contact_person_role' => 'Responsable',
                'location_id' => $this->location()->id,
                'proof_document_id' => $this->proof()->id,
            ]);

            $detail = $this->explain($request);
            $this->assertSame(100, $detail['score'], $type->value.' : '.json_encode($detail));
            $this->assertSame([], $detail['warnings'], $type->value);
        }
    }

    public function test_each_criterion_has_its_weight(): void
    {
        // Dossier vide : seule la pénalité de contact joue (l'adresse absente est un avertissement sans point).
        $this->assertSame(
            ['score' => 0, 'reasons' => [], 'warnings' => ['Contact humain incomplet.', 'Adresse absente ou non rattachée.']],
            $this->explain($this->makeRequest(['organization_type' => OrganizationType::EXTERNE]))
        );

        $this->assertSame(20, $this->score(self::BASE));
        $this->assertSame(10, $this->score(['organization_type' => OrganizationType::EXTERNE, 'phone' => '082000000']));
        $this->assertSame(25, $this->score([...self::BASE, 'contact_person_role' => 'Chef']));
        $this->assertSame(45, $this->score([...self::BASE, 'proof_document_id' => $this->proof()->id]));
        // Adresse renseignée +5, vérifiée +5, dans la commune +10 (hors commune −5).
        $this->assertSame(40, $this->score([...self::BASE, 'location_id' => $this->location()->id]));
        $this->assertSame(35, $this->score([...self::BASE, 'location_id' => $this->location(verified: false)->id]));
        $this->assertSame(25, $this->score([...self::BASE, 'location_id' => $this->location(city: 'Namur')->id]));
        $this->assertSame(20, $this->score([...self::BASE, 'location_id' => $this->location(verified: false, city: 'Namur')->id]));
    }

    public function test_enterprise_number_is_checked_for_format_and_control_key(): void
    {
        $this->assertTrue(EnterpriseNumber::isValid('0123.456.749'));
        $this->assertTrue(EnterpriseNumber::isValid('BE 0123 456 749'));
        $this->assertTrue(EnterpriseNumber::isValid('123456749'));
        $this->assertTrue(EnterpriseNumber::isValid('0202.239.951'));
        $this->assertFalse(EnterpriseNumber::isValid('0123.456.789')); // clé de contrôle fausse
        $this->assertFalse(EnterpriseNumber::isValid('2123.456.749')); // ne commence ni par 0 ni par 1
        $this->assertFalse(EnterpriseNumber::isValid('0123.456'));
        $this->assertFalse(EnterpriseNumber::isValid('abc'));
        $this->assertFalse(EnterpriseNumber::isValid(null));

        $valid = $this->explain($this->makeRequest([...self::BASE, 'enterprise_number' => self::VALID_BCE]));
        $this->assertSame(45, $valid['score']);
        $this->assertContains('Numéro BCE valide (format et clé de contrôle).', $valid['reasons']);

        $invalid = $this->explain($this->makeRequest([...self::BASE, 'enterprise_number' => '0123.456.789']));
        $this->assertSame(5, $invalid['score']);
        $this->assertContains('Numéro BCE invalide (format ou clé de contrôle incorrects).', $invalid['warnings']);
    }

    public function test_an_asbl_without_enterprise_number_is_penalized_but_other_types_are_not(): void
    {
        $asbl = $this->explain($this->makeRequest([...self::BASE, 'organization_type' => OrganizationType::ASBL]));
        $cpas = $this->explain($this->makeRequest([...self::BASE, 'organization_type' => OrganizationType::CPAS]));

        $this->assertContains('Numéro BCE manquant pour une ASBL.', $asbl['warnings']);
        $this->assertNotContains('Numéro BCE manquant pour une ASBL.', $cpas['warnings']);
        $this->assertSame(10, $asbl['score']);
        $this->assertSame(20, $cpas['score']);
    }

    public function test_email_domain_bonus_and_free_provider_warning(): void
    {
        $request = $this->makeRequest([...self::BASE, 'organization_name' => 'ASBL Les Amis du Parc', 'official_email' => 'info@amisduparc.be']);
        $named = $this->explain($request);
        $this->assertSame(25, $named['score']);
        $this->assertContains("Le domaine de l'email officiel correspond au nom de l'organisation.", $named['reasons']);

        $request->update(['official_email' => 'info@autrechose.be']);
        $other = $this->explain($request);
        $this->assertSame(20, $other['score']);
        $this->assertNotContains("Le domaine de l'email officiel correspond au nom de l'organisation.", $other['reasons']);

        // Adresse grand public : avertissement, aucun retrait de points, aucun bonus.
        $request->update(['official_email' => 'amisduparc@gmail.com']);
        $free = $this->explain($request);
        $this->assertSame(20, $free['score']);
        $this->assertContains("Adresse email grand public (gmail.com) : vérifier qu'elle est bien celle de l'organisation.", $free['warnings']);
        $this->assertNotContains("Le domaine de l'email officiel correspond au nom de l'organisation.", $free['reasons']);
    }

    public function test_duplicate_enterprise_number_is_flagged_against_pending_and_approved_requests_only(): void
    {
        $first = $this->makeRequest([...self::BASE, 'enterprise_number' => self::VALID_BCE, 'status' => RequestStatus::APPROUVE]);

        $duplicate = $this->makeRequest([...self::BASE, 'enterprise_number' => 'BE 0123 456 749']); // autre écriture du même numéro
        $detail = $this->explain($duplicate);
        $this->assertSame(45 - 20, $detail['score']);
        $this->assertContains("Numéro BCE déjà utilisé par la demande n°{$first->id}.", $detail['warnings']);

        $different = $this->explain($this->makeRequest([...self::BASE, 'enterprise_number' => self::OTHER_VALID_BCE]));
        $this->assertSame(45, $different['score']);

        // Une demande refusée ne compte pas.
        $first->update(['status' => RequestStatus::REFUSE]);
        $this->assertSame(45, $this->explain($duplicate)['score']);
    }

    public function test_nearly_identical_names_are_flagged(): void
    {
        $this->assertTrue(OrganizationName::areNearlyIdentical('ASBL Les Amis du Parc', 'amis du parc'));
        $this->assertTrue(OrganizationName::areNearlyIdentical('Café du Parc', 'Cafe du Parc ASBL'));
        $this->assertTrue(OrganizationName::areNearlyIdentical('Solidaire Dinant', 'Solidaire Dinan')); // une coquille
        $this->assertFalse(OrganizationName::areNearlyIdentical('Amis du Parc', 'Amis du Lac'));
        $this->assertFalse(OrganizationName::areNearlyIdentical('Cercle Horizon', 'Cercle Falaise'));

        Organization::create(['name' => 'Café du Parc', 'type' => OrganizationType::ASBL, 'official_email' => 'parc@example.test']);

        $same = $this->explain($this->makeRequest([...self::BASE, 'organization_name' => 'ASBL Cafe du Parc']));
        $this->assertSame(10, $same['score']);
        $this->assertContains('Nom presque identique à « Café du Parc » (organisation ou demande existante).', $same['warnings']);

        $this->assertSame(20, $this->score([...self::BASE, 'organization_name' => 'ASBL Les Sentiers']));

        // Une autre demande en attente ou approuvée compte aussi.
        $this->makeRequest([...self::BASE, 'organization_name' => 'Club Alpha', 'status' => RequestStatus::APPROUVE]);
        $this->assertSame(10, $this->score([...self::BASE, 'organization_name' => 'Club Alphá']));
    }

    public function test_requester_history_lowers_or_raises_the_score(): void
    {
        $requester = User::factory()->create();
        $target = $this->makeRequest([...self::BASE, 'proof_document_id' => $this->proof()->id, 'request_by_user_id' => $requester->id]);
        $this->assertSame(45, $this->explain($target)['score']);

        $this->makeRequest(['request_by_user_id' => $requester->id, 'status' => RequestStatus::REFUSE]);
        $one = $this->explain($target);
        $this->assertSame(35, $one['score']);
        $this->assertContains('Le demandeur a déjà eu une demande de création refusée.', $one['warnings']);

        $this->makeRequest(['request_by_user_id' => $requester->id, 'status' => RequestStatus::REFUSE]);
        $two = $this->explain($target);
        $this->assertSame(25, $two['score']);
        $this->assertContains('Le demandeur a déjà eu 2 demandes de création refusées.', $two['warnings']);

        $this->makeRequest(['request_by_user_id' => $requester->id, 'status' => RequestStatus::REFUSE]);
        $this->assertSame(25, $this->explain($target)['score']); // pénalité plafonnée à −20

        $good = User::factory()->create();
        $goodTarget = $this->makeRequest([...self::BASE, 'request_by_user_id' => $good->id]);
        $this->makeRequest(['request_by_user_id' => $good->id, 'status' => RequestStatus::APPROUVE]);
        $detail = $this->explain($goodTarget);
        $this->assertSame(25, $detail['score']);
        $this->assertContains('Le demandeur a déjà une organisation créée par une demande approuvée.', $detail['reasons']);
    }

    public function test_state_dependent_checks_only_apply_to_pending_requests(): void
    {
        $this->makeRequest([...self::BASE, 'enterprise_number' => self::VALID_BCE, 'organization_name' => 'Club Alpha']);
        $processed = $this->makeRequest([
            ...self::BASE,
            'enterprise_number' => self::VALID_BCE,
            'organization_name' => 'Club Alpha',
            'status' => RequestStatus::APPROUVE,
        ]);

        $detail = $this->explain($processed);
        $this->assertSame(45, $detail['score']);
        $this->assertSame(['Adresse absente ou non rattachée.'], $detail['warnings']); // ni doublon de BCE, ni nom proche
    }

    public function test_score_is_clamped_between_0_and_100(): void
    {
        $refused = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->makeRequest(['request_by_user_id' => $refused->id, 'status' => RequestStatus::REFUSE]);
        }
        $this->assertSame(0, $this->score(['request_by_user_id' => $refused->id, 'organization_name' => 'AB', 'enterprise_number' => '0123.456.789']));

        // Dossier complet (100) + demande précédente approuvée (+5) : plafonné à 100.
        $good = User::factory()->create();
        $this->makeRequest(['request_by_user_id' => $good->id, 'status' => RequestStatus::APPROUVE]);
        $this->assertSame(100, $this->score([
            'request_by_user_id' => $good->id,
            'organization_name' => 'Pervenche Solidaire',
            'organization_type' => OrganizationType::ASBL,
            'official_email' => 'info@pervenche.be',
            'enterprise_number' => self::VALID_BCE,
            'phone' => '082000000',
            'contact_person_name' => 'Alice',
            'contact_person_role' => 'Responsable',
            'location_id' => $this->location()->id,
            'proof_document_id' => $this->proof()->id,
        ]));
    }

    public function test_confidence_level_thresholds_and_api_fields(): void
    {
        $this->assertNull(ConfidenceLevel::fromScore(null));
        $this->assertSame('faible', ConfidenceLevel::fromScore(0));
        $this->assertSame('faible', ConfidenceLevel::fromScore(39));
        $this->assertSame('moyen', ConfidenceLevel::fromScore(40));
        $this->assertSame('moyen', ConfidenceLevel::fromScore(69));
        $this->assertSame('eleve', ConfidenceLevel::fromScore(70));
        $this->assertSame('eleve', ConfidenceLevel::fromScore(100));
        $this->assertSame('Élevé', ConfidenceLevel::label('eleve'));
        $this->assertNull(ConfidenceLevel::label(null));

        $agent = User::factory()->create();
        $agent->assignRole('agent_communal');
        Sanctum::actingAs($agent);

        // Demande traitée : le niveau suit la note figée de la décision.
        $processed = $this->makeRequest(['status' => RequestStatus::REFUSE, 'confidence_note' => 69]);
        $this->getJson("/api/org-registration-requests/{$processed->id}")
            ->assertOk()
            ->assertJsonPath('data.confidence_note', 69)
            ->assertJsonPath('data.confidence_level', 'moyen')
            ->assertJsonPath('data.confidence_level_label', 'Moyen');

        // Demande en attente : le niveau suit le score courant.
        $pending = $this->makeRequest([...self::BASE, 'enterprise_number' => self::VALID_BCE, 'proof_document_id' => $this->proof()->id]);
        $this->getJson("/api/org-registration-requests/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.confidence_note', 70)
            ->assertJsonPath('data.confidence_details.score', 70)
            ->assertJsonPath('data.confidence_level', 'eleve')
            ->assertJsonPath('data.confidence_level_label', 'Élevé');

        $this->getJson('/api/org-registration-requests?statuses[]=refuse')
            ->assertJsonPath('data.0.confidence_level', 'moyen');
    }

    /** @param array<string, mixed> $attributes */
    private function makeRequest(array $attributes = []): OrgRegistrationRequest
    {
        $this->sequence++;
        $name = self::NAMES[$this->sequence % count(self::NAMES)].' '.$this->sequence;

        return OrgRegistrationRequest::create([
            'request_by_user_id' => User::factory()->create()->id,
            'organization_name' => $name,
            'organization_type' => OrganizationType::ASBL,
            'official_email' => "demande{$this->sequence}@exemple.test",
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 50,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function score(array $attributes = []): int
    {
        return $this->explain($this->makeRequest($attributes))['score'];
    }

    private function explain(OrgRegistrationRequest $request): array
    {
        return app(OrgRegistrationConfidenceScoreService::class)->explain($request->fresh());
    }

    /** Numéro BCE valide construit depuis ses 8 premiers chiffres (clé = 97 − base modulo 97). */
    private function validBce(int $base): string
    {
        return sprintf('%08d%02d', $base, 97 - ($base % 97));
    }

    private function location(bool $verified = true, string $city = 'Dinant'): Location
    {
        return Location::create([
            'formatted_address' => "Rue du Centre 1, {$city}",
            'city' => $city,
            'country' => 'Belgique',
            'is_verified' => $verified,
        ]);
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
