<?php

namespace App\Http\Requests\DriverJourney;

use App\Http\Requests\Concerns\ValidatesDevicePosition;
use Illuminate\Foundation\Http\FormRequest;

/** Corps de POST /api/driver-alerts : trajet en cours (facultatif), message (facultatif), position du téléphone. */
class StoreDriverAlertRequest extends FormRequest
{
    use ValidatesDevicePosition;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'message' => ['nullable', 'string', 'max:500'],
            ...$this->positionRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'reservation_id.exists' => 'Ce trajet n’existe pas.',
            'message.max' => 'Le message ne peut pas dépasser 500 caractères.',
            ...$this->positionMessages(),
        ];
    }
}
