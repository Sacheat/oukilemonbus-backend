<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;
use App\Models\User;
use App\Models\UserNotificationPreference;

/**
 * Gère les préférences de notification d'un utilisateur.
 *
 * `forUser()` crée les préférences avec les valeurs par défaut si elles n'existent pas encore
 * (création paresseuse — aucune migration de données nécessaire pour les anciens comptes).
 *
 * `channelEnabled()` mappe le couple (catégorie, canal) à la colonne booléenne correspondante.
 * Une combinaison inconnue retourne true (permissive by default).
 *
 * `inQuietHours()` gère les plages de silence nocturne y compris le cas minuit-croisé :
 * ex. quiet_hours_start = 22, quiet_hours_end = 7 → actif de 22h à 6h59 (Europe/Brussels).
 */
class NotificationPreferenceService
{
    public function forUser(User $user): UserNotificationPreference
    {
        return $user->notificationPreference()->firstOrCreate(
            ['user_id' => $user->id],
            $this->defaults()
        );
    }

    public function update(User $user, array $data): UserNotificationPreference
    {
        $preference = $this->forUser($user);
        $preference->update($data);

        return $preference->refresh();
    }

    public function channelEnabled(User $user, string $category, string $channel): bool
    {
        $preference = $this->forUser($user);
        $column = match ([$category, $channel]) {
            ['reservation_updates', 'database'] => 'notify_reservation_updates_in_app',
            ['reservation_updates', 'mail'] => 'notify_reservation_updates_email',
            ['driver_planning', 'database'] => 'notify_driver_planning_in_app',
            ['driver_planning', 'mail'] => 'notify_driver_planning_email',
            ['admin_workflow', 'database'] => 'notify_admin_workflow_in_app',
            ['admin_workflow', 'mail'] => 'notify_admin_workflow_email',
            ['last_minute_cancellation', 'database'] => 'notify_last_minute_cancellation_in_app',
            ['last_minute_cancellation', 'mail'] => 'notify_last_minute_cancellation_email',
            ['weekly_planning', 'database'] => 'notify_weekly_planning_in_app',
            ['weekly_planning', 'mail'] => 'notify_weekly_planning_email',
            default => null,
        };

        if ($column === null) {
            return true;
        }

        return (bool) $preference->getAttribute($column);
    }

    public function defaults(): array
    {
        return [
            'notify_reservation_updates_in_app' => true,
            'notify_reservation_updates_email' => true,
            'notify_driver_planning_in_app' => true,
            'notify_driver_planning_email' => true,
            'notify_admin_workflow_in_app' => true,
            'notify_admin_workflow_email' => true,
            'notify_last_minute_cancellation_in_app' => true,
            'notify_last_minute_cancellation_email' => true,
            // Planning hebdomadaire : null = automatique (le responsable désigné, sinon tous), voir WeeklyPlanningService
            'weekly_planning_opt_in' => null,
            'notify_weekly_planning_in_app' => true,
            'notify_weekly_planning_email' => true,
            'digest_frequency' => 'none',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ];
    }

    public function digestFrequency(User $user): string
    {
        return (string) $this->forUser($user)->digest_frequency;
    }

    public function inQuietHours(User $user): bool
    {
        $preference = $this->forUser($user);
        $start = $preference->quiet_hours_start;
        $end = $preference->quiet_hours_end;

        if ($start === null || $end === null) {
            return false;
        }

        $hour = (int) CarbonImmutable::now('Europe/Brussels')->format('G');

        if ($start === $end) {
            return true;
        }

        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }

        return $hour >= $start || $hour < $end;
    }
}
