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
