<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\User;

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
        if (! method_exists($user, 'organization')) {
            return null;
        }

        $organization = $user->organization;

        if ($organization === null) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name ?? null,
        ];
    }

    private function capabilities(User $user): array
    {
        $capabilities = [];

        if ($this->can($user, 'reservations.view')) {
            $capabilities[] = 'Consulter les réservations';
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

        if ($this->can($user, 'reservations.view')) {
            $capabilities[] = 'Consulter les réservations et le planning';
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
