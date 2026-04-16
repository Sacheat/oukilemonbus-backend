<?php

namespace App\Services\UserOrgRequests;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\UserOrgRequest;
use App\Services\Locations\MunicipalityService;

class UserOrgRequestConfidenceScoreService
{
    public function __construct(
        private readonly MunicipalityService $municipalityService
    ) {}

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
        $this->scoreRequester($request, $score, $reasons);
        $this->scoreOrganization($request, $score, $reasons, $warnings);
        $this->scoreRoleCoherence($request, $score, $reasons, $warnings);
        $this->applyWarnings($request, $score, $warnings);

        return [
            'score' => max(0, min(100, $score)),
            'reasons' => $reasons,
            'warnings' => $warnings,
        ];
    }

    private function scoreCompleteness(UserOrgRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        $this->addWhenFilled($request->requested_role, 10, 'Role demande renseigne.', $score, $reasons);
        $this->addWhenFilled($request->justification, 15, 'Justification fournie.', $score, $reasons);
        $this->addWhenFilled($request->proof_document_path, 20, 'Document justificatif fourni.', $score, $reasons);

        if (filled($request->justification) && mb_strlen(trim($request->justification)) >= 20) {
            $score += 5;
            $reasons[] = 'Justification suffisamment detaillee.';
        }

        if (blank($request->justification) && blank($request->proof_document_path)) {
            $score -= 10;
            $warnings[] = 'Demande sans justification ni justificatif.';
        }
    }

    private function scoreRequester(UserOrgRequest $request, int &$score, array &$reasons): void
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
            $score += 10;
            $reasons[] = 'Email du demandeur verifie.';
        }

        if (filled($request->requestedByUser->phone)) {
            $score += 5;
            $reasons[] = 'Telephone du demandeur renseigne.';
        }

        if ($request->requestedByUser->organizations()->exists()) {
            $score += 5;
            $reasons[] = 'Demandeur deja rattache a une organisation.';
        }
    }

    private function scoreOrganization(UserOrgRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        if (! $request->relationLoaded('organization')) {
            $request->load('organization.location');
        } elseif (! $request->organization?->relationLoaded('location')) {
            $request->organization?->load('location');
        }

        if (! $request->organization) {
            $warnings[] = 'Organisation introuvable.';

            return;
        }

        $score += 10;
        $reasons[] = 'Organisation existante.';

        if (filled($request->organization->official_email)) {
            $score += 5;
            $reasons[] = 'Email officiel de l organisation renseigne.';
        }

        if (filled($request->organization->phone_contact) || filled($request->organization->name_contact)) {
            $score += 5;
            $reasons[] = 'Contact de l organisation renseigne.';
        }

        if (filled($request->organization->document_proof_path)) {
            $score += 5;
            $reasons[] = 'Justificatif de l organisation present.';
        }

        if ($request->organization->location?->is_verified) {
            $score += 5;
            $reasons[] = 'Adresse de l organisation verifiee.';
        }

        if (! $request->organization->location) {
            $warnings[] = 'Organisation sans adresse rattachee.';

            return;
        }

        if ($this->municipalityService->containsLocation($request->organization->location)) {
            $score += 10;
            $reasons[] = "Organisation situee dans la commune de {$this->municipalityService->name()}.";

            return;
        }

        $score -= 5;
        $warnings[] = "Organisation hors commune de {$this->municipalityService->name()}.";
    }

    private function scoreRoleCoherence(UserOrgRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        if (! $request->organization) {
            return;
        }

        $expectedRoles = match ($request->organization->type) {
            OrganizationType::ECOLE_COMMUNALE => ['prof', 'directeur', 'referent', 'responsable'],
            OrganizationType::ASBL => ['animateur', 'responsable', 'referent'],
            OrganizationType::EXTERNE => ['responsable', 'referent'],
        };

        if (in_array($request->requested_role, $expectedRoles, true)) {
            $score += 10;
            $reasons[] = "Role coherent avec le type d'organisation.";

            return;
        }

        $score -= 5;
        $warnings[] = "Role moins coherent avec le type d'organisation.";
    }

    private function applyWarnings(UserOrgRequest $request, int &$score, array &$warnings): void
    {
        $otherPendingRequests = UserOrgRequest::query()
            ->where('requested_by_user_id', $request->requested_by_user_id)
            ->where('status', RequestStatus::EN_ATTENTE)
            ->whereKeyNot($request->getKey())
            ->exists();

        if ($otherPendingRequests) {
            $score -= 5;
            $warnings[] = 'Le demandeur a deja une autre demande de rattachement en attente.';
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
