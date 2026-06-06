<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'notify_reservation_updates_in_app',
    'notify_reservation_updates_email',
    'notify_driver_planning_in_app',
    'notify_driver_planning_email',
    'notify_admin_workflow_in_app',
    'notify_admin_workflow_email',
    'notify_last_minute_cancellation_in_app',
    'notify_last_minute_cancellation_email',
    'digest_frequency',
    'quiet_hours_start',
    'quiet_hours_end',
])]
/**
 * Préférences de notification d'un utilisateur.
 * Créée automatiquement par NotificationPreferenceService::forUser() si elle n'existe pas encore.
 * `digest_frequency` : none | daily | weekly — détermine la fréquence des emails récapitulatifs.
 * `quiet_hours_start` / `quiet_hours_end` : plage horaire pendant laquelle les notifications
 * sont mises en file d'attente plutôt qu'envoyées immédiatement (0–23h).
 */
class UserNotificationPreference extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'notify_reservation_updates_in_app' => 'boolean',
            'notify_reservation_updates_email' => 'boolean',
            'notify_driver_planning_in_app' => 'boolean',
            'notify_driver_planning_email' => 'boolean',
            'notify_admin_workflow_in_app' => 'boolean',
            'notify_admin_workflow_email' => 'boolean',
            'notify_last_minute_cancellation_in_app' => 'boolean',
            'notify_last_minute_cancellation_email' => 'boolean',
            'quiet_hours_start' => 'integer',
            'quiet_hours_end' => 'integer',
        ];
    }
}
