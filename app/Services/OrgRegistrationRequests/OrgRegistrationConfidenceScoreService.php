<?php

namespace App\Services\OrgRegistrationRequests;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Services\Locations\MunicipalityService;
use App\Support\EnterpriseNumber;
use App\Support\OrganizationName;
use Illuminate\Support\Str;

/**
 * Calcule et explique le score de confiance (0–100) d'une demande de création d'organisation.
 *
 * C'est un barème de points écrit à la main, qui trie la file des agents (dossiers complets et cohérents d'abord). Il ne
 * prouve rien : il n'ouvre pas le justificatif et ne vérifie pas que l'entreprise existe.
 *
 * Points (un dossier complet et cohérent fait 100, quel que soit le type d'organisation) :
 *   - Complétude (50) : téléphone +10, personne de contact +10, son rôle +5, adresse +5, justificatif +25.
 *   - Numéro BCE (25) : format et clé de contrôle valides +25 (voir EnterpriseNumber).
 *   - Localisation (15) : adresse vérifiée +5, située dans la commune +10 (hors commune −5).
 *   - Email officiel (5) : le domaine reprend un mot significatif du nom de l'organisation +5.
 *   Pas de points pour l'email officiel, ni pour le compte du demandeur actif ou vérifié : les routes les exigent déjà,
 *   ils sont acquis à tout le monde et ne distinguent personne.
 *
 * Pénalités : BCE saisi mais invalide −15, BCE manquant pour une ASBL −10, ni téléphone ni contact −10,
 * nom de moins de 4 caractères −5. Avertissements sans point : email grand public (gmail, hotmail…), adresse absente.
 *
 * Contrôles de l'état du moment, évalués seulement pour une demande EN_ATTENTE (sur une demande traitée, l'organisation
 * créée par son approbation les ferait déclencher) :
 *   - BCE déjà utilisé par une autre demande en attente ou approuvée −20 ; nom presque identique à une organisation
 *     ou demande existante −10 ; email officiel déjà utilisé par une organisation −15 ;
 *   - autre demande en attente du même demandeur −5 ;
 *   - historique du demandeur : −10 par demande refusée (−20 au plus), +5 s'il a déjà une demande approuvée.
 *
 * `calculate()` retourne uniquement le score ; `explain()` retourne le détail complet
 * (score, raisons positives, avertissements) — utilisé par les Resources pour l'affichage.
 */
class OrgRegistrationConfidenceScoreService
{
    /** Fournisseurs d'adresses grand public : signalés à l'agent, sans retrait de points (beaucoup de petites ASBL les utilisent). */
    private const array FREE_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.be', 'hotmail.fr', 'outlook.com', 'outlook.be',
        'outlook.fr', 'live.com', 'live.be', 'live.fr', 'msn.com', 'yahoo.com', 'yahoo.fr', 'icloud.com', 'me.com',
        'skynet.be', 'telenet.be', 'proximus.be', 'belgacom.net', 'gmx.com', 'gmx.net', 'proton.me', 'protonmail.com',
        'orange.fr', 'free.fr', 'laposte.net',
    ];

    private const int PENALTY_PER_REFUSAL = 10;

    private const int MAX_REFUSAL_PENALTY = 20;

    public function __construct(
        private readonly MunicipalityService $municipalityService
    ) {}

    public function calculate(OrgRegistrationRequest $request): int
    {
        return $this->explain($request)['score'];
    }

    public function explain(OrgRegistrationRequest $request): array
    {
        $score = 0;
        $reasons = [];
        $warnings = [];

        $this->scoreCompleteness($request, $score, $reasons, $warnings);
        $this->scoreEnterpriseNumber($request, $score, $reasons, $warnings);
        $this->scoreLocation($request, $score, $reasons, $warnings);
        $this->scoreEmailDomain($request, $score, $reasons, $warnings);
        $this->applyIntrinsicWarnings($request, $score, $warnings);

        if ($request->status?->isPending()) {
            $this->applyCurrentStateChecks($request, $score, $reasons, $warnings);
        }

        return [
            'score' => max(0, min(100, $score)),
            'reasons' => $reasons,
            'warnings' => $warnings,
        ];
    }

    private function scoreCompleteness(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        $this->addWhenFilled($request->phone, 10, 'Téléphone de contact renseigné.', $score, $reasons);
        $this->addWhenFilled($request->contact_person_name, 10, 'Personne de contact renseignée.', $score, $reasons);
        $this->addWhenFilled($request->contact_person_role, 5, 'Rôle de la personne de contact renseigné.', $score, $reasons);
        $this->addWhenFilled($request->location_id, 5, 'Adresse renseignée.', $score, $reasons);
        $this->addWhenFilled($request->proof_document_id, 25, 'Document justificatif fourni.', $score, $reasons);

        if (blank($request->phone) && blank($request->contact_person_name)) {
            $score -= 10;
            $warnings[] = 'Contact humain incomplet.';
        }
    }

    private function scoreEnterpriseNumber(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        if (filled($request->enterprise_number)) {
            if (EnterpriseNumber::isValid($request->enterprise_number)) {
                $score += 25;
                $reasons[] = 'Numéro BCE valide (format et clé de contrôle).';

                return;
            }

            $score -= 15;
            $warnings[] = 'Numéro BCE invalide (format ou clé de contrôle incorrects).';

            return;
        }

        if ($request->organization_type === OrganizationType::ASBL) {
            $score -= 10;
            $warnings[] = 'Numéro BCE manquant pour une ASBL.';
        }
    }

    private function scoreLocation(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        if (! $request->relationLoaded('location')) {
            $request->load('location');
        }

        if (! $request->location) {
            $warnings[] = 'Adresse absente ou non rattachée.';

            return;
        }

        if ($request->location->is_verified) {
            $score += 5;
            $reasons[] = 'Adresse vérifiée.';
        }

        if ($this->municipalityService->containsLocation($request->location)) {
            $score += 10;
            $reasons[] = "Adresse situee dans la commune de {$this->municipalityService->name()}.";

            return;
        }

        $score -= 5;
        $warnings[] = "Adresse hors commune de {$this->municipalityService->name()}.";
    }

    private function scoreEmailDomain(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        $domain = Str::lower((string) Str::after((string) $request->official_email, '@'));

        if ($domain === '') {
            return;
        }

        if (in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
            $warnings[] = "Adresse email grand public ({$domain}) : vérifier qu'elle est bien celle de l'organisation.";

            return;
        }

        // Domaine sans son extension (« amisduparc.be » → « amisduparc ») : il doit contenir un mot significatif du nom.
        $label = preg_replace('/[^a-z0-9]+/', '', Str::beforeLast($domain, '.'));
        $nameWords = array_filter(OrganizationName::words((string) $request->organization_name), fn (string $word): bool => strlen($word) >= 4);

        foreach ($nameWords as $word) {
            if (str_contains($label, $word)) {
                $score += 5;
                $reasons[] = "Le domaine de l'email officiel correspond au nom de l'organisation.";

                return;
            }
        }
    }

    private function applyIntrinsicWarnings(OrgRegistrationRequest $request, int &$score, array &$warnings): void
    {
        if (mb_strlen(trim((string) $request->organization_name)) < 4) {
            $score -= 5;
            $warnings[] = "Nom d'organisation trop court.";
        }
    }

    private function applyCurrentStateChecks(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        $this->checkDuplicateEnterpriseNumber($request, $score, $warnings);
        $this->checkSimilarName($request, $score, $warnings);

        if (Organization::query()->where('official_email', $request->official_email)->exists()) {
            $score -= 15;
            $warnings[] = 'Email officiel déjà utilisé par une organisation existante.';
        }

        $otherRequests = OrgRegistrationRequest::query()
            ->where('request_by_user_id', $request->request_by_user_id)
            ->whereKeyNot($request->getKey());

        if ((clone $otherRequests)->where('status', RequestStatus::EN_ATTENTE)->exists()) {
            $score -= 5;
            $warnings[] = 'Le demandeur a déjà une autre demande en attente.';
        }

        $refusals = (clone $otherRequests)->where('status', RequestStatus::REFUSE)->count();

        if ($refusals > 0) {
            $score -= min(self::MAX_REFUSAL_PENALTY, $refusals * self::PENALTY_PER_REFUSAL);
            $warnings[] = $refusals === 1
                ? 'Le demandeur a déjà eu une demande de création refusée.'
                : "Le demandeur a déjà eu {$refusals} demandes de création refusées.";
        }

        if ((clone $otherRequests)->where('status', RequestStatus::APPROUVE)->exists()) {
            $score += 5;
            $reasons[] = 'Le demandeur a déjà une organisation créée par une demande approuvée.';
        }
    }

    private function checkDuplicateEnterpriseNumber(OrgRegistrationRequest $request, int &$score, array &$warnings): void
    {
        $number = EnterpriseNumber::normalize($request->enterprise_number);

        if ($number === null) {
            return;
        }

        // Une organisation n'a pas de colonne BCE : on compare aux demandes en attente ou approuvées (une demande
        // approuvée correspond à une organisation créée).
        $duplicate = OrgRegistrationRequest::query()
            ->whereKeyNot($request->getKey())
            ->whereIn('status', [RequestStatus::EN_ATTENTE, RequestStatus::APPROUVE])
            ->whereNotNull('enterprise_number')
            ->get(['id', 'enterprise_number'])
            ->first(fn (OrgRegistrationRequest $other): bool => EnterpriseNumber::normalize($other->enterprise_number) === $number);

        if ($duplicate !== null) {
            $score -= 20;
            $warnings[] = "Numéro BCE déjà utilisé par la demande n°{$duplicate->id}.";
        }
    }

    private function checkSimilarName(OrgRegistrationRequest $request, int &$score, array &$warnings): void
    {
        $name = (string) $request->organization_name;

        if (OrganizationName::key($name) === '') {
            return;
        }

        $knownNames = Organization::query()->pluck('name')
            ->merge(OrgRegistrationRequest::query()
                ->whereKeyNot($request->getKey())
                ->whereIn('status', [RequestStatus::EN_ATTENTE, RequestStatus::APPROUVE])
                ->pluck('organization_name'));

        $similar = $knownNames->first(fn (string $known): bool => OrganizationName::areNearlyIdentical($name, $known));

        if ($similar !== null) {
            $score -= 10;
            $warnings[] = "Nom presque identique à « {$similar} » (organisation ou demande existante).";
        }
    }

    private function addWhenFilled(mixed $value, int $points, string $reason, int &$score, array &$reasons): void
    {
        if (filled($value)) {
            $score += $points;
            $reasons[] = $reason;
        }
    }
}
