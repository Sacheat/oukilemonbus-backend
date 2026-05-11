<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Policy de gestion des accès aux documents générés
 * Règles d'accès :
 * - Les utilisateurs avec les rôles internes (admin, agent communal) ont accès à tous les documents
 * - Les utilisateurs externes (responsable d'organisation, chauffeur) n'ont accès qu'aux documents liés à leurs réservations ou à leur organisation, et uniquement si ces documents sont disponibles (is_available = true)
 * - Seuls les utilisateurs avec le droit 'documents.download' peuvent télécharger les documents, en plus des règles ci-dessus
 * - Seuls les utilisateurs avec le droit 'documents.generate' et un rôle interne peuvent générer des documents
 * - Seuls les utilisateurs avec le droit 'documents.update' et un rôle interne peuvent mettre à jour les documents (ex: pour marquer comme officiel, etc.)
 */
class DocumentPolicy
{
    private const INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    public function view(User $user, Document $document): bool
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

    public function download(User $user, Document $document): bool
    {
        return $user->can('documents.download')
            && $this->view($user, $document);
    }

    public function generate(User $user): bool
    {
        return $user->can('documents.generate')
            && $this->isInternal($user);
    }

    public function update(User $user, Document $document): bool
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
