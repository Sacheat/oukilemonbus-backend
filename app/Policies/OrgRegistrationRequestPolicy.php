<?php

namespace App\Policies;

use App\Models\OrgRegistrationRequest;
use App\Models\User;

class OrgRegistrationRequestPolicy
{
    private const array INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('org-registration-requests.view');
    }

    public function view(User $user, OrgRegistrationRequest $orgRegistrationRequest): bool
    {
        if ($this->isRequester($user, $orgRegistrationRequest)) {
            return true;
        }

        return $user->can('org-registration-requests.view')
            && $this->isInternal($user);
    }

    public function create(User $user): bool
    {
        return $user->can('org-registration-requests.create');
    }

    public function approve(User $user, OrgRegistrationRequest $orgRegistrationRequest): bool
    {
        return $user->can('org-registration-requests.review')
            && $this->isInternal($user);
    }

    public function reject(User $user, OrgRegistrationRequest $orgRegistrationRequest): bool
    {
        return $user->can('org-registration-requests.review')
            && $this->isInternal($user);
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }

    private function isRequester(User $user, OrgRegistrationRequest $orgRegistrationRequest): bool
    {
        return $orgRegistrationRequest->request_by_user_id === $user->getKey();
    }
}
