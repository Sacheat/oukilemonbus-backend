<?php

namespace App\Http\Requests\Unavailabilities;

use App\Enums\UnavailabilityReasonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnavailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('unavailabilities.create') ?? false;
    }

    // Si reason_type = driver
    // → driver_id doit être renseigné
    // → vehicle_id doit être null

    // Si reason_type = vehicle
    // → vehicle_id doit être renseigné
    // → driver_id doit être null

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
            'reason_type.required' => 'Le type d\'indisponibilite est obligatoire.',
            'start_datetime.required' => 'La date de debut est obligatoire.',
            'start_datetime.before' => 'La date de debut doit etre avant la date de fin.',
            'end_datetime.required' => 'La date de fin est obligatoire.',
            'end_datetime.after' => 'La date de fin doit etre apres la date de debut.',
            'driver_id.exists' => 'Ce chauffeur n\'existe pas.',
            'vehicle_id.exists' => 'Ce vehicule n\'existe pas.',
        ];
    }
}
