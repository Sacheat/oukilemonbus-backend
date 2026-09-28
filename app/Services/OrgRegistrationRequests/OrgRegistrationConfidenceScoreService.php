<?php

namespace App\Services\OrgRegistrationRequests;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Services\Locations\MunicipalityService;

/**
 * Calcule et explique le score de confiance (0–100) d'une demande de création d'organisation.
 *
 * Le score est composé de quatre axes :
 *   1. Complétude (email, téléphone, contact, justificatif, adresse) → jusqu'à +51 pts
 *   2. Cohérence du type (numéro BCE obligatoire pour ASBL) → ±15 pts
 *   3. Localisation (adresse vérifiée ORS, dans la commune) → jusqu'à +15 pts / −5 hors commune
 *   4. Signaux demandeur (compte actif, email vérifié) → jusqu'à +15 pts
 *
 * Pénalités appliquées par applyWarnings() : nom trop court, email déjà utilisé,
 * autre demande en attente du même utilisateur.
 *
 * `calculate()` retourne uniquement le score ; `explain()` retourne le détail complet
 * (score, raisons positives, avertissements) — utilisé par les Resources pour l'affichage.
 */
class OrgRegistrationConfidenceScoreService
{
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
        $this->scoreTypeCoherence($request, $score, $reasons, $warnings);
        $this->scoreLocation($request, $score, $reasons, $warnings);
        $this->scoreRequester($request, $score, $reasons);
        $this->applyWarnings($request, $score, $warnings);

        return [
            'score' => max(0, min(100, $score)),
            'reasons' => $reasons,
            'warnings' => $warnings,
        ];
    }

    private function scoreCompleteness(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        $this->addWhenFilled($request->official_email, 10, 'Email officiel renseigné.', $score, $reasons);
        $this->addWhenFilled($request->phone, 8, 'Téléphone de contact renseigné.', $score, $reasons);
        $this->addWhenFilled($request->contact_person_name, 8, 'Personne de contact renseignée.', $score, $reasons);
        $this->addWhenFilled($request->contact_person_role, 5, 'Rôle de la personne de contact renseigné.', $score, $reasons);
        $this->addWhenFilled($request->location_id, 5, 'Adresse renseignée.', $score, $reasons);
        $this->addWhenFilled($request->proof_document_id, 15, 'Document justificatif fourni.', $score, $reasons);

        if (blank($request->phone) && blank($request->contact_person_name)) {
            $score -= 10;
            $warnings[] = 'Contact humain incomplet.';
        }
    }

    private function scoreTypeCoherence(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        if ($request->organization_type === OrganizationType::ASBL) {
            if (filled($request->enterprise_number)) {
                $score += 15;
                $reasons[] = 'Numéro BCE fourni pour une ASBL.';

                return;
            }

            $score -= 10;
            $warnings[] = 'Numéro BCE manquant pour une ASBL.';

            return;
        }

        if (filled($request->enterprise_number)) {
            $score += 5;
            $reasons[] = 'Numéro BCE fourni.';
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

    private function scoreRequester(OrgRegistrationRequest $request, int &$score, array &$reasons): void
    {
        if (! $request->relationLoaded('requestedByUser')) {
            $request->load('requestedByUser');
        }

        if (! $request->requestedByUser) {
            return;
        }

        if ($request->requestedByUser->active) {
            $score += 10;
            $reasons[] = 'Compte demandeur actif.';
        }

        if ($request->requestedByUser->email_verified_at !== null) {
            $score += 5;
            $reasons[] = 'Email du demandeur vérifié.';
        }
    }

    private function applyWarnings(OrgRegistrationRequest $request, int &$score, array &$warnings): void
    {
        if (mb_strlen(trim($request->organization_name)) < 4) {
            $score -= 5;
            $warnings[] = "Nom d'organisation trop court.";
        }

        $emailAlreadyUsed = Organization::query()
            ->where('official_email', $request->official_email)
            ->exists();

        if ($emailAlreadyUsed) {
            $score -= 15;
            $warnings[] = 'Email officiel déjà utilisé par une organisation existante.';
        }

        $otherPendingRequests = OrgRegistrationRequest::query()
            ->where('request_by_user_id', $request->request_by_user_id)
            ->where('status', RequestStatus::EN_ATTENTE)
            ->whereKeyNot($request->getKey())
            ->exists();

        if ($otherPendingRequests) {
            $score -= 5;
            $warnings[] = 'Le demandeur a déjà une autre demande en attente.';
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
