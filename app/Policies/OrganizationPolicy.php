<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

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

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }
}
