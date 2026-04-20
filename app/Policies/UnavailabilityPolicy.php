<?php

namespace App\Policies;

use App\Models\Unavailability;
use App\Models\User;

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
