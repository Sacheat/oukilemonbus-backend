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
