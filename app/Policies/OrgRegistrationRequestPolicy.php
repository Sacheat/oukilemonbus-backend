<?php

namespace App\Policies;

use App\Models\OrgRegistrationRequest;
use App\Models\User;

/**
 * Contrôle l'accès aux demandes d'inscription d'organisation.
 *
 * view() : le demandeur accède à sa propre demande sans permission explicite ;
 *          les internes nécessitent la permission org-registration-requests.view.
 * create() : ouvert à tout utilisateur ayant la permission — pas de restriction interne.
 * update() : le demandeur OU tout interne peut modifier (pas de vérification de permission).
 * approve() / reject() : réservés aux internes avec la permission .review.
 */
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

    public function update(User $user, OrgRegistrationRequest $orgRegistrationRequest): bool
    {
        return $this->isRequester($user, $orgRegistrationRequest)
            || $this->isInternal($user);
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
