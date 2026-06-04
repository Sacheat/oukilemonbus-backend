<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserOrgRequest;

class UserOrgRequestPolicy
{
    private const array INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('user-org-requests.view');
    }

    public function view(User $user, UserOrgRequest $userOrgRequest): bool
    {
        if ($this->isRequester($user, $userOrgRequest)) {
            return true;
        }

        if (! $user->can('user-org-requests.view')) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        return $user->hasRole('responsable_organisation')
            && $this->belongsToUserOrganization($user, $userOrgRequest);
    }

    public function create(User $user): bool
    {
        return $user->can('user-org-requests.create');
    }

    public function update(User $user, UserOrgRequest $userOrgRequest): bool
    {
        return $this->isRequester($user, $userOrgRequest)
            || $this->isInternal($user);
    }

    public function approve(User $user, UserOrgRequest $userOrgRequest): bool
    {
        return $user->can('user-org-requests.review')
            && $this->isInternal($user);
    }

    public function reject(User $user, UserOrgRequest $userOrgRequest): bool
    {
        return $user->can('user-org-requests.review')
            && $this->isInternal($user);
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }

    private function isRequester(User $user, UserOrgRequest $userOrgRequest): bool
    {
        return $userOrgRequest->requested_by_user_id === $user->getKey();
    }

    private function belongsToUserOrganization(User $user, UserOrgRequest $userOrgRequest): bool
    {
        return $user->organizations()
            ->whereKey($userOrgRequest->organization_id)
            ->exists();
    }
}
