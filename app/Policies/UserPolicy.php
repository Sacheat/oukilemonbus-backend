<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserOrganization;

/**
 * Contrôle l'accès aux profils utilisateur.
 *
 * view() : un utilisateur voit toujours son propre profil ; les internes voient tout
 *          le monde ; un responsable peut voir les membres des organisations qu'il gère.
 * update() : self-edit autorisé, ou interne avec permission.
 * updatePassword() : self-only, sans vérification de permission — le mot de passe
 *                    n'est modifiable que par l'utilisateur lui-même.
 * updateStatus() / deactivate() : réservés aux internes.
 * updateRoles() : attribution des rôles globaux, réservée à l'admin.
 * sendPasswordReset() : envoi d'un lien de réinitialisation du mot de passe, réservé à l'admin.
 */
class UserPolicy
{
    private const array INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('users.view')
            && $this->isInternal($user);
    }

    public function view(User $user, User $targetUser): bool
    {
        if ($user->is($targetUser)) {
            return true;
        }

        if (! $user->can('users.view')) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        return $this->managesOrganizationOf($user, $targetUser);
    }

    public function create(User $user): bool
    {
        return $user->can('users.create')
            && $this->isInternal($user);
    }

    public function update(User $user, User $targetUser): bool
    {
        if ($user->is($targetUser)) {
            return true;
        }

        return $user->can('users.update')
            && $this->isInternal($user);
    }

    public function updatePassword(User $user, User $targetUser): bool
    {
        return $user->is($targetUser);
    }

    public function updateStatus(User $user, User $targetUser): bool
    {
        return $user->can('users.update-status')
            && $this->isInternal($user);
    }

    public function updateRoles(User $user, User $targetUser): bool
    {
        return $user->can('users.manage-roles')
            && $user->hasRole('admin');
    }

    public function sendPasswordReset(User $user, User $targetUser): bool
    {
        return $user->can('users.send-password-reset')
            && $user->hasRole('admin');
    }

    public function deactivate(User $user, User $targetUser): bool
    {
        return $user->can('users.deactivate')
            && $this->isInternal($user);
    }

    /** Export RGPD : chacun pour son compte, un admin (users.export-data) pour tout compte. */
    public function exportData(User $user, User $targetUser): bool
    {
        return $user->is($targetUser)
            || ($user->can('users.export-data') && $this->isInternal($user));
    }

    /** Effacement RGPD : admin uniquement (users.anonymize), jamais sur son propre compte. */
    public function anonymize(User $user, User $targetUser): bool
    {
        return $user->can('users.anonymize')
            && $this->isInternal($user)
            && ! $user->is($targetUser);
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }

    private function managesOrganizationOf(User $user, User $targetUser): bool
    {
        return $user->organizations()
            ->wherePivotIn('relationship_type', UserOrganization::MANAGER_TYPES)
            ->whereIn(
                'organizations.id',
                $targetUser->organizations()->select('organizations.id')
            )
            ->exists();
    }
}
