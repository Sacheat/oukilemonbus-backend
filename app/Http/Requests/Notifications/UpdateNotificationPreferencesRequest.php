<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'notify_reservation_updates_in_app' => ['sometimes', 'boolean'],
            'notify_reservation_updates_email' => ['sometimes', 'boolean'],
            'notify_driver_planning_in_app' => ['sometimes', 'boolean'],
            'notify_driver_planning_email' => ['sometimes', 'boolean'],
            'notify_admin_workflow_in_app' => ['sometimes', 'boolean'],
            'notify_admin_workflow_email' => ['sometimes', 'boolean'],
            'notify_last_minute_cancellation_in_app' => ['sometimes', 'boolean'],
            'notify_last_minute_cancellation_email' => ['sometimes', 'boolean'],
            'digest_frequency' => ['sometimes', 'string', 'in:none,hourly,daily'],
            'quiet_hours_start' => ['sometimes', 'integer', 'between:0,23'],
            'quiet_hours_end' => ['sometimes', 'integer', 'between:0,23'],
        ];
    }
}
