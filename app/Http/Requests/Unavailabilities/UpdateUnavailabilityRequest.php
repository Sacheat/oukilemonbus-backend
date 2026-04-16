<?php

namespace App\Http\Requests\Unavailabilities;

use App\Enums\UnavailabilityReasonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnavailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('unavailabilities.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason_type'    => ['sometimes', Rule::enum(UnavailabilityReasonType::class)],
            'reason'         => ['sometimes', 'nullable', 'string', 'max:500'],
            'driver_id'      => ['sometimes', 'nullable', 'integer', 'exists:drivers,id'],
            'vehicle_id'     => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
            'start_datetime' => ['sometimes', 'date', 'before:end_datetime'],
            'end_datetime'   => ['sometimes', 'date', 'after:start_datetime'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_datetime.before' => 'La date de debut doit etre avant la date de fin.',
            'end_datetime.after'    => 'La date de fin doit etre apres la date de debut.',
            'driver_id.exists'      => 'Ce chauffeur n\'existe pas.',
            'vehicle_id.exists'     => 'Ce vehicule n\'existe pas.',
        ];
    }
}
