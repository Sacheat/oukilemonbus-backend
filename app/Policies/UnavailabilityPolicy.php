<?php

namespace App\Policies;

use App\Models\Unavailability;
use App\Models\User;

/**
 * Contrôle l'accès aux indisponibilités de chauffeurs et véhicules.
 *
 * view() : les internes voient toutes les indisponibilités ; un chauffeur ne voit
 *          que les indisponibilités liées à son propre profil Driver (driver_id).
 *          Les véhicules n'ont pas de visibilité restreinte par rôle externe.
 * create() / update() / cancel() : réservés aux internes — les chauffeurs ne peuvent
 *          pas déclarer ni modifier leurs propres indisponibilités directement.
 */
class UnavailabilityPolicy
{
    private const array INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('unavailabilities.view');
    }

    public function view(User $user, Unavailability $unavailability): bool
    {
        if (! $user->can('unavailabilities.view')) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        return $user->hasRole('chauffeur')
            && $user->driver !== null
            && $unavailability->driver_id === $user->driver->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can('unavailabilities.create')
            && $this->isInternal($user);
    }

    public function update(User $user, Unavailability $unavailability): bool
    {
        return $user->can('unavailabilities.update')
            && $this->isInternal($user);
    }

    public function cancel(User $user, Unavailability $unavailability): bool
    {
        return $user->can('unavailabilities.cancel')
            && $this->isInternal($user);
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }
}
