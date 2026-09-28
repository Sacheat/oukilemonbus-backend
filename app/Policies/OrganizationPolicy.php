<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Contrôle l'accès aux organisations.
 *
 * view() : les internes (admin, agent_communal) voient toutes les organisations ;
 *          les autres utilisateurs ne voient que celles auxquelles ils appartiennent.
 * create() / update() : réservés aux internes.
 * ban() : réservé au rôle admin uniquement — agent_communal exclu même avec permission.
 */
class OrganizationPolicy
{
    private const array INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('organizations.view');
    }

    public function view(User $user, Organization $organization): bool
    {
        if (! $user->can('organizations.view')) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        return $user->organizations()
            ->whereKey($organization->getKey())
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('organizations.create')
            && $this->isInternal($user);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->can('organizations.update')
            && $this->isInternal($user);
    }

    /** Changer le type d'un membre ou le retirer : responsable de l'organisation, ou interne. */
    public function manageMembers(User $user, Organization $organization): bool
    {
        if ($this->isInternal($user)) {
            return $user->can('organizations.update');
        }

        return $user->managesOrganization($organization);
    }

    /** Quitter l'organisation ou en faire son organisation par défaut : tout membre. */
    public function leave(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function ban(User $user, Organization $organization): bool
    {
        return $user->can('organizations.ban')
            && $user->hasRole('admin');
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }
}
