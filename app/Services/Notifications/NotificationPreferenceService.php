<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotificationPreference;

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
        ];
    }
}

