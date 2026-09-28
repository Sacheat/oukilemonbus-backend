<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserOrgRequest;

/**
 * Contrôle l'accès aux demandes d'adhésion d'un utilisateur à une organisation.
 *
 * view() : le demandeur accède à sa propre demande sans permission explicite ;
 *          les internes et les responsables de l'organisation concernée ont aussi accès.
 * create() : ouvert à tout utilisateur ayant la permission — pas de restriction interne.
 * update() : le demandeur OU tout interne peut modifier (pas de vérification de permission).
 * approve() / reject() : réservés aux internes avec la permission .review.
 */
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

        // Un responsable voit les demandes d'adhésion à son organisation.
        return $user->managesOrganization($userOrgRequest->organization_id);
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
}
