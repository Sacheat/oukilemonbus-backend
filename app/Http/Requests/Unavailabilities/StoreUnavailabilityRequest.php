<?php

namespace App\Http\Requests\Unavailabilities;

use App\Enums\UnavailabilityReasonType;
use App\Models\Unavailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requête de création d'indisponibilité.
 * La cohérence entre reason_type et driver_id/vehicle_id n'est pas validée ici —
 * elle est vérifiée par UnavailabilityService qui lance UnavailabilityException si incohérent.
 */
class StoreUnavailabilityRequest extends FormRequest
{
    use NormalizesUnavailabilityDates;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Unavailability::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason_type' => ['required', Rule::enum(UnavailabilityReasonType::class)],
            'reason' => ['nullable', 'string', 'max:500'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'start_datetime' => ['required', 'date', 'before:end_datetime'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_type.required' => 'Le type d\'indisponibilité est obligatoire.',
            'start_datetime.required' => 'La date de début est obligatoire.',
            'start_datetime.before' => 'La date de début doit être avant la date de fin.',
            'end_datetime.required' => 'La date de fin est obligatoire.',
            'end_datetime.after' => 'La date de fin doit être après la date de début.',
            'driver_id.exists' => 'Ce chauffeur n\'existe pas.',
            'vehicle_id.exists' => 'Ce véhicule n\'existe pas.',
        ];
    }
}
