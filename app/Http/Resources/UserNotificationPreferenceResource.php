<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserNotificationPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'notify_reservation_updates_in_app' => $this->notify_reservation_updates_in_app,
            'notify_reservation_updates_email' => $this->notify_reservation_updates_email,
            'notify_driver_planning_in_app' => $this->notify_driver_planning_in_app,
            'notify_driver_planning_email' => $this->notify_driver_planning_email,
            'notify_admin_workflow_in_app' => $this->notify_admin_workflow_in_app,
            'notify_admin_workflow_email' => $this->notify_admin_workflow_email,
            'notify_last_minute_cancellation_in_app' => $this->notify_last_minute_cancellation_in_app,
            'notify_last_minute_cancellation_email' => $this->notify_last_minute_cancellation_email,
            'weekly_planning_opt_in' => $this->weekly_planning_opt_in,
            'notify_weekly_planning_in_app' => $this->notify_weekly_planning_in_app,
            'notify_weekly_planning_email' => $this->notify_weekly_planning_email,
            'digest_frequency' => $this->digest_frequency,
            'quiet_hours_start' => $this->quiet_hours_start,
            'quiet_hours_end' => $this->quiet_hours_end,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
