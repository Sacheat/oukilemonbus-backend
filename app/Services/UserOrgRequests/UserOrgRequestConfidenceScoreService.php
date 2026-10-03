<?php

namespace App\Services\UserOrgRequests;

use App\Models\UserOrganization;
use App\Models\UserOrgRequest;
use Illuminate\Support\Str;

/**
 * Calcule et explique le score de confiance (0–100) d'une demande d'adhésion à une organisation.
 *
 * C'est un barème de points écrit à la main, qui trie la file des agents (dossiers étayés et cohérents d'abord). Il ne
 * prouve rien : il n'ouvre pas le justificatif et ne vérifie pas que la personne appartient vraiment à l'organisation.
 *
 * Points (une demande complète fait 100) :
 *   - Justificatif fourni +35.
 *   - Justification fournie +25 ; détaillée (au moins 30 caractères et 5 mots) +15.
 *   - Cohérence rôle / justification +20 : la justification contient au moins un mot entier du vocabulaire du rôle
 *     (ROLE_VOCABULARY, nom du rôle exclu : écrire « responsable » ne prouve rien pour le rôle « responsable »).
 *   - Téléphone du demandeur renseigné +5.
 *   Pas de points pour l'organisation cible ni le rôle (champs obligatoires), ni pour le compte actif ou l'email vérifié
 *   (les routes les exigent) : ils sont acquis à tout le monde et ne distinguent personne.
 *
 * Pénalités : ni justification ni justificatif −15 ; justification de moins de 15 caractères −5 ; rôle sensible
 * (directeur, responsable, référent) sans justificatif −10.
 *
 * Avertissements sans point : justification de moins de 4 mots ; et, pour une demande EN_ATTENTE seulement (sur une
 * demande traitée, l'approbation elle-même les ferait apparaître ou les rendrait caducs) :
 *   - rôle déjà tenu : responsable ou directeur demandé alors que l'organisation en a déjà un ;
 *   - même justification qu'une autre demande du même demandeur sur les 90 derniers jours.
 *
 * `calculate()` retourne uniquement le score ; `explain()` retourne le détail complet
 * (score, raisons positives, avertissements) — utilisé par les Resources pour l'affichage.
 */
class UserOrgRequestConfidenceScoreService
{
    /** Mots entiers (sans accent, en minuscules) qui rendent une justification cohérente avec le rôle demandé. */
    private const array ROLE_VOCABULARY = [
        'animateur' => ['animation', 'animations', 'animer', 'activite', 'activites', 'atelier', 'ateliers', 'enfant', 'enfants', 'jeunes', 'stage', 'stages'],
        'directeur' => ['direction', 'diriger', 'dirige', 'etablissement', 'ecole', 'equipe', 'personnel'],
        'prof' => ['enseignant', 'enseignants', 'enseigne', 'enseigner', 'enseignement', 'classe', 'classes', 'ecole', 'eleve', 'eleves', 'cours', 'instituteur', 'institutrice', 'titulaire'],
        'responsable' => ['coordination', 'coordonne', 'coordonner', 'gestion', 'gere', 'gerer', 'equipe', 'organisation', 'association', 'comite', 'inscriptions'],
        'referent' => ['contact', 'coordination', 'coordonne', 'planning', 'suivi', 'interlocuteur', 'liaison', 'relais'],
    ];

    private const int REPEATED_JUSTIFICATION_DAYS = 90;

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
        $this->scoreRequesterPhone($request, $score, $reasons);

        if ($request->status?->isPending()) {
            $this->checkRoleAlreadyHeld($request, $warnings);
            $this->checkRepeatedJustification($request, $warnings);
        }

        return [
            'score' => max(0, min(100, $score)),
            'reasons' => $reasons,
            'warnings' => $warnings,
        ];
    }

    private function scoreCompleteness(UserOrgRequest $request, int &$score, array &$reasons, array &$warnings): void
    {
        $this->addWhenFilled($request->justification, 25, 'Justification fournie.', $score, $reasons);
        $this->addWhenFilled($request->proof_document_id, 35, 'Document justificatif fourni.', $score, $reasons);

        $justification = trim((string) $request->justification);
        $length = mb_strlen($justification);
        $words = $this->words($justification);

        if ($length >= 30 && count($words) >= 5) {
            $score += 15;
            $reasons[] = 'Justification suffisamment détaillée.';
        }

        if (blank($request->justification) && blank($request->proof_document_id)) {
            $score -= 15;
            $warnings[] = 'Demande sans justification ni justificatif.';
        }

        if ($justification !== '' && $length < 15) {
            $score -= 5;
            $warnings[] = 'Justification trop courte.';
        } elseif ($justification !== '' && count($words) < 4) {
            $warnings[] = 'Justification très courte (moins de 4 mots).';
        }

        if ($this->requiresProof($request->requested_role) && blank($request->proof_document_id)) {
            $score -= 10;
            $warnings[] = 'Rôle sensible sans justificatif.';
        }
    }

    private function scoreRoleJustificationCoherence(UserOrgRequest $request, int &$score, array &$reasons): void
    {
        if (blank($request->requested_role) || blank($request->justification)) {
            return;
        }

        $vocabulary = self::ROLE_VOCABULARY[$request->requested_role] ?? [];

        if (array_intersect($this->words((string) $request->justification), $vocabulary) !== []) {
            $score += 20;
            $reasons[] = 'Justification cohérente avec le rôle demandé.';
        }
    }

    private function scoreRequesterPhone(UserOrgRequest $request, int &$score, array &$reasons): void
    {
        if (! $request->relationLoaded('requestedByUser')) {
            $request->load('requestedByUser');
        }

        if (filled($request->requestedByUser?->phone)) {
            $score += 5;
            $reasons[] = 'Téléphone du demandeur renseigné.';
        }
    }

    private function checkRoleAlreadyHeld(UserOrgRequest $request, array &$warnings): void
    {
        if (! in_array($request->requested_role, UserOrganization::MANAGER_TYPES, true)) {
            return;
        }

        if (! $request->relationLoaded('organization')) {
            $request->load('organization');
        }

        $holders = $request->organization?->users()
            ->wherePivotIn('relationship_type', UserOrganization::MANAGER_TYPES)
            ->where('users.id', '!=', $request->requested_by_user_id)
            ->orderBy('users.id')
            ->get(['users.id', 'users.first_name', 'users.last_name']);

        if ($holders === null || $holders->isEmpty()) {
            return;
        }

        $names = $holders->take(3)
            ->map(fn ($user): string => trim("{$user->first_name} {$user->last_name}")." ({$user->pivot->relationship_type})")
            ->implode(', ');

        $warnings[] = "Rôle déjà tenu : l'organisation a déjà un responsable ou directeur : {$names}".($holders->count() > 3 ? '…' : '').'.';
    }

    private function checkRepeatedJustification(UserOrgRequest $request, array &$warnings): void
    {
        $normalized = implode(' ', $this->words((string) $request->justification));

        if ($normalized === '') {
            return;
        }

        $repeated = UserOrgRequest::query()
            ->where('requested_by_user_id', $request->requested_by_user_id)
            ->whereKeyNot($request->getKey())
            ->whereNotNull('justification')
            ->where('created_at', '>=', now()->subDays(self::REPEATED_JUSTIFICATION_DAYS))
            ->get(['request_id', 'justification'])
            ->first(fn (UserOrgRequest $other): bool => implode(' ', $this->words((string) $other->justification)) === $normalized);

        if ($repeated !== null) {
            $warnings[] = "Même justification que la demande n°{$repeated->request_id} du même demandeur.";
        }
    }

    private function requiresProof(?string $role): bool
    {
        return in_array($role, ['directeur', 'responsable', 'referent'], true);
    }

    /** @return list<string> mots entiers, sans accent, en minuscules */
    private function words(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY);
    }

    private function addWhenFilled(mixed $value, int $points, string $reason, int &$score, array &$reasons): void
    {
        if (filled($value)) {
            $score += $points;
            $reasons[] = $reason;
        }
    }
}
