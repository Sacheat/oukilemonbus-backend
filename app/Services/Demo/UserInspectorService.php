<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\User;

/**
 * Construit la fiche d'inspection d'un utilisateur pour le panneau Backend Inspector.
 *
 * `inspect()` retourne les rôles, permissions et capacités lisibles de l'acteur.
 * Les capacités (capabilities) sont traduites en phrases françaises pour le panneau démo
 * — elles ne correspondent pas aux permissions Spatie mais à des groupes fonctionnels.
 *
 * Compatible avec les utilisateurs sans Spatie (méthodes testées via method_exists) pour
 * éviter les erreurs fatales si le service est appelé hors contexte Spatie.
 *
 * `displayName()` utilise par priorité : user.name → first_name + last_name → email.
 * L'organisation affichée est celle marquée is_default, sinon la première disponible.
 */
final class UserInspectorService
{
    public function inspect(?User $user): array
    {
        if ($user === null) {
            return [
                'selected' => false,
                'message' => 'Aucun utilisateur sélectionné dans la démo.',
            ];
        }

        return [
            'selected' => true,
            'id' => $user->id,
            'name' => $this->displayName($user),
            'email' => $user->email,
            'roles' => $this->roles($user),
            'permissions' => $this->permissions($user),
            'organization' => $this->organization($user),
            'capabilities' => $this->capabilities($user),
        ];
    }

    private function displayName(User $user): string
    {
        $fullName = trim((string) ($user->name ?? ''));

        if ($fullName !== '') {
            return $fullName;
        }

        $firstName = trim((string) ($user->first_name ?? ''));
        $lastName = trim((string) ($user->last_name ?? ''));

        $composedName = trim($firstName . ' ' . $lastName);

        if ($composedName !== '') {
            return $composedName;
        }

        return (string) $user->email;
    }

    private function roles(User $user): array
    {
        if (! method_exists($user, 'getRoleNames')) {
            return [];
        }

        return $user->getRoleNames()
            ->values()
            ->all();
    }

    private function permissions(User $user): array
    {
        if (! method_exists($user, 'getAllPermissions')) {
            return [];
        }

        return $user->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    private function organization(User $user): ?array
    {
        if (! method_exists($user, 'organizations')) {
            return null;
        }

        $organizations = $user->relationLoaded('organizations')
            ? $user->organizations
            : $user->organizations()->get();

        $organization = $organizations->first(function ($organization): bool {
            return (bool) ($organization->pivot?->is_default ?? false);
        }) ?? $organizations->first();

        if ($organization === null) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name ?? null,
            'relationship_type' => $organization->pivot?->relationship_type ?? null,
            'is_default' => (bool) ($organization->pivot?->is_default ?? false),
        ];
    }

    private function capabilities(User $user): array
    {
        $capabilities = [];

        if ($this->can($user, 'reservations.view')) {
            $capabilities[] = 'Consulter les réservations et le planning';
        }

        if ($this->can($user, 'reservations.create')) {
            $capabilities[] = 'Créer une réservation';
        }

        if ($this->can($user, 'reservations.approve')) {
            $capabilities[] = 'Approuver ou refuser une réservation';
        }

        if ($this->can($user, 'reservations.assign')) {
            $capabilities[] = 'Assigner un chauffeur ou un véhicule';
        }

        if ($this->can($user, 'documents.generate')) {
            $capabilities[] = 'Générer des documents';
        }

        if ($this->can($user, 'notifications.monitor')) {
            $capabilities[] = 'Surveiller les notifications';
        }

        if ($this->can($user, 'backend-inspector.view')) {
            $capabilities[] = 'Utiliser le Backend Inspector';
        }

        return $capabilities;
    }

    private function can(User $user, string $permission): bool
    {
        return method_exists($user, 'can') && $user->can($permission);
    }
}
