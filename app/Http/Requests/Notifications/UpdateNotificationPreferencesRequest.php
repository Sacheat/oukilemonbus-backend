<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            // null = heures silencieuses désactivées. Les deux bornes vont ensemble (voir after()).
            'quiet_hours_start' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
        ];
    }

    /**
     * Heures silencieuses : début et fin renseignés ensemble, ou tous deux à null pour les désactiver.
     * L'erreur est placée sur la borne manquante.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sendsStart = $this->has('quiet_hours_start');
                $sendsEnd = $this->has('quiet_hours_end');

                if (! $sendsStart && ! $sendsEnd) {
                    return;
                }

                $start = $this->input('quiet_hours_start');
                $end = $this->input('quiet_hours_end');

                if (($start === null) === ($end === null)) {
                    return;
                }

                $validator->errors()->add(
                    $start === null ? 'quiet_hours_start' : 'quiet_hours_end',
                    'Les heures silencieuses doivent avoir une heure de début et une heure de fin, ou aucune des deux pour les désactiver.'
                );
            },
        ];
    }
}
