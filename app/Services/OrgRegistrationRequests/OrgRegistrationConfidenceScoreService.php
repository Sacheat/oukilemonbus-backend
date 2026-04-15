<?php

namespace App\Services\OrgRegistrationRequests;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Services\Locations\MunicipalityService;

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
        $this->addWhenFilled($request->official_email, 10, 'Email officiel renseigne.', $score, $reasons);
        $this->addWhenFilled($request->phone, 8, 'Telephone de contact renseigne.', $score, $reasons);
        $this->addWhenFilled($request->contact_person_name, 8, 'Personne de contact renseignee.', $score, $reasons);
        $this->addWhenFilled($request->contact_person_role, 5, 'Role de la personne de contact renseigne.', $score, $reasons);
        $this->addWhenFilled($request->location_id, 5, 'Adresse renseignee.', $score, $reasons);
        $this->addWhenFilled($request->proof_document_path, 15, 'Document justificatif fourni.', $score, $reasons);

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
                $reasons[] = 'Numero BCE fourni pour une ASBL.';

                return;
            }

            $score -= 10;
            $warnings[] = 'Numero BCE manquant pour une ASBL.';

            return;
        }

        if (filled($request->enterprise_number)) {
            $score += 5;
            $reasons[] = 'Numero BCE fourni.';
        }
    }

    private function scoreLocation(OrgRegistrationRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        if (! $request->relationLoaded('location')) {
            $request->load('location');
        }

        if (! $request->location) {
            $warnings[] = 'Adresse absente ou non rattachee.';

            return;
        }

        if ($request->location->is_verified) {
            $score += 5;
            $reasons[] = 'Adresse verifiee.';
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
            $reasons[] = 'Email du demandeur verifie.';
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
            $warnings[] = 'Email officiel deja utilise par une organisation existante.';
        }

        $otherPendingRequests = OrgRegistrationRequest::query()
            ->where('request_by_user_id', $request->request_by_user_id)
            ->where('status', RequestStatus::EN_ATTENTE)
            ->whereKeyNot($request->getKey())
            ->exists();

        if ($otherPendingRequests) {
            $score -= 5;
            $warnings[] = 'Le demandeur a deja une autre demande en attente.';
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
