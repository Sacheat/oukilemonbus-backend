<?php

namespace App\Services\UserOrgRequests;

use App\Models\UserOrgRequest;

/**
 * Calcule et explique le score de confiance (0–100) d'une demande d'adhésion à une organisation.
 *
 * Le score est composé de trois axes :
 *   1. Complétude : organisation cible (+10), rôle (+15), justification (+20), justificatif (+25),
 *      justification suffisamment détaillée ≥ 30 chars (+10). Pénalités si vide ou trop court.
 *   2. Cohérence rôle / justification : mots-clés sémantiques par rôle → +10 si alignés.
 *   3. Signaux demandeur : compte actif (+3), email vérifié (+4), téléphone renseigné (+3).
 *
 * `requiresProof()` identifie les rôles sensibles (directeur, responsable, referent)
 * qui nécessitent impérativement un justificatif.
 */
class UserOrgRequestConfidenceScoreService
{
    public function calculate(UserOrgRequest $request): int
    {
        return $this->explain($request)['score'];
    }

    public function explain(UserOrgRequest $request): array
    {
        $score = 0;
        $reasons = [];
        $warnings = [];

        $this->scoreCompleteness($request, $score, $reasons, $warnings);
        $this->scoreRoleJustificationCoherence($request, $score, $reasons);
        $this->scoreRequesterSignals($request, $score, $reasons);

        return [
            'score' => max(0, min(100, $score)),
            'reasons' => $reasons,
            'warnings' => $warnings,
        ];
    }

    private function scoreCompleteness(UserOrgRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        $this->addWhenFilled($request->organization_id, 10, 'Organisation cible selectionnee.', $score, $reasons);
        $this->addWhenFilled($request->requested_role, 15, 'Role demande renseigne.', $score, $reasons);
        $this->addWhenFilled($request->justification, 20, 'Justification fournie.', $score, $reasons);
        $this->addWhenFilled($request->proof_document_id, 25, 'Document justificatif fourni.', $score, $reasons);

        if (filled($request->justification) && mb_strlen(trim($request->justification)) >= 30) {
            $score += 10;
            $reasons[] = 'Justification suffisamment detaillee.';
        }

        if (blank($request->justification) && blank($request->proof_document_id)) {
            $score -= 15;
            $warnings[] = 'Demande sans justification ni justificatif.';
        }

        if (filled($request->justification) && mb_strlen(trim($request->justification)) < 15) {
            $score -= 5;
            $warnings[] = 'Justification trop courte.';
        }

        if ($this->requiresProof($request->requested_role) && blank($request->proof_document_id)) {
            $score -= 5;
            $warnings[] = 'Role sensible sans justificatif.';
        }
    }

    private function scoreRoleJustificationCoherence(UserOrgRequest $request, int &$score, array &$reasons): void
    {
        if (blank($request->requested_role) || blank($request->justification)) {
            return;
        }

        $keywords = match ($request->requested_role) {
            'animateur' => ['animation', 'animateur', 'activite', 'atelier', 'enfant'],
            'directeur' => ['direction', 'directeur', 'responsable', 'etablissement', 'ecole'],
            'prof' => ['prof', 'professeur', 'enseignant', 'classe', 'ecole', 'eleve'],
            'responsable' => ['responsable', 'coordination', 'gestion', 'referent', 'contact'],
            'referent' => ['referent', 'contact', 'coordination', 'planning', 'suivi'],
            default => [],
        };

        if ($this->containsAny($request->justification, $keywords)) {
            $score += 10;
            $reasons[] = 'Justification coherente avec le role demande.';
        }
    }

    private function scoreRequesterSignals(UserOrgRequest $request, int &$score, array &$reasons): void
    {
        if (! $request->relationLoaded('requestedByUser')) {
            $request->load('requestedByUser');
        }

        if (! $request->requestedByUser) {
            return;
        }

        if ($request->requestedByUser->active) {
            $score += 3;
            $reasons[] = 'Compte demandeur actif.';
        }

        if ($request->requestedByUser->email_verified_at !== null) {
            $score += 4;
            $reasons[] = 'Email du demandeur verifie.';
        }

        if (filled($request->requestedByUser->phone)) {
            $score += 3;
            $reasons[] = 'Telephone du demandeur renseigne.';
        }
    }

    private function requiresProof(?string $role): bool
    {
        return in_array($role, ['directeur', 'responsable', 'referent'], true);
    }

    private function containsAny(string $value, array $keywords): bool
    {
        $normalizedValue = str($value)
            ->ascii()
            ->lower()
            ->toString();

        foreach ($keywords as $keyword) {
            if (str_contains($normalizedValue, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function addWhenFilled(mixed $value, int $points, string $reason, int &$score, array &$reasons): void
    {
        if (filled($value)) {
            $score += $points;
            $reasons[] = $reason;
        }
    }
}
