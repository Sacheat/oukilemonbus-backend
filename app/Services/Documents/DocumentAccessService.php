<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Service centralisant les règles d'accès aux documents.
 *
 * Il sert à la fois :
 * - à construire les requêtes filtrées pour lister les documents visibles ;
 * - à vérifier les droits sur un document précis ;
 * - à éviter de dupliquer la logique entre DocumentPolicy et DocumentService.
 */
class DocumentAccessService
{
    private const INTERNAL_ROLES = ['admin', 'agent_communal'];

    /**
     * @param User $user L'utilisateur pour lequel on souhaite construire la requête de documents visibles
     * @return Builder La requête Eloquent de documents filtrée selon les règles d'accès pour l'utilisateur donné
     */
    public function visibleQueryFor(User $user): Builder
    {
        $query = Document::query();

        if (! $this->canViewAny($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isInternal($user)) {
            return $query;
        }

        return $query
            ->where('is_available', true)
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->getKey())
                    ->orWhereHas('reservation', function (Builder $reservationQuery) use ($user): void {
                        $reservationQuery->where('requested_by_user_id', $user->getKey());

                        if ($user->hasRole('responsable_organisation')) {
                            $organizationIds = $user->organizations()
                                ->pluck('organizations.id')
                                ->all();

                            if ($organizationIds !== []) {
                                $reservationQuery->orWhereIn('organization_id', $organizationIds);
                            }
                        }

                        if ($user->hasRole('chauffeur') && $user->driver !== null) {
                            $reservationQuery->orWhere('driver_id', $user->driver->getKey());
                        }
                    });
            });
    }

    /**
     * Vérifie si un utilisateur donné a le droit de voir un document précis, en appliquant les règles d'accès définies pour la visibilité des documents
     *
     * @param User $user L'utilisateur pour lequel on souhaite vérifier le droit de voir le document
     * @param Document $document Le document pour lequel on souhaite vérifier le droit de visibilité
     * @return bool true si l'utilisateur a le droit de voir le document, false sinon
     */
    public function canViewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    public function canView(User $user, Document $document): bool
    {
        if (! $user->can('documents.view')) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        if (! $document->is_available) {
            return false;
        }

        if ($document->user_id === $user->getKey()) {
            return true;
        }

        $reservation = $document->reservation;

        if ($reservation === null) {
            return false;
        }

        if ($reservation->requested_by_user_id === $user->getKey()) {
            return true;
        }

        if (
            $user->hasRole('responsable_organisation')
            && $this->belongsToUserOrganization($user, $reservation->organization_id)
        ) {
            return true;
        }

        return $user->hasRole('chauffeur')
            && $user->driver !== null
            && $reservation->driver_id === $user->driver->getKey();
    }

    /**
     * Vérifie si un utilisateur donné a le droit de télécharger un document précis, en appliquant les règles d'accès définies pour le téléchargement des documents
     *
     * @param User $user L'utilisateur pour lequel on souhaite vérifier le droit de télécharger le document
     * @param Document $document Le document pour lequel on souhaite vérifier le droit de téléchargement
     * @return bool true si l'utilisateur a le droit de télécharger le document, false sinon
     */
    public function canDownload(User $user, Document $document): bool
    {
        return $user->can('documents.download')
            && $this->canView($user, $document);
    }

    public function canGenerate(User $user): bool
    {
        return $user->can('documents.generate')
            && $this->isInternal($user);
    }

    public function canUpdate(User $user, Document $document): bool
    {
        return $user->can('documents.update')
            && $this->isInternal($user);
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }

    private function belongsToUserOrganization(User $user, mixed $organizationId): bool
    {
        if ($organizationId === null) {
            return false;
        }

        return $user->organizations()
            ->whereKey($organizationId)
            ->exists();
    }
}
