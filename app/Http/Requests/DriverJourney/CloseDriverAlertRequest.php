<?php

namespace App\Http\Requests\DriverJourney;

use Illuminate\Foundation\Http\FormRequest;

/** Corps de POST /api/driver-alerts/{alert}/close : ce qui a été fait (obligatoire). */
class CloseDriverAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['closing_note' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function messages(): array
    {
        return [
            'closing_note.required' => 'Indiquez ce qui a été fait (dépanneuse, bus de remplacement, fausse alerte…).',
            'closing_note.min' => 'Indiquez ce qui a été fait (dépanneuse, bus de remplacement, fausse alerte…).',
            'closing_note.max' => 'La note ne peut pas dépasser 1000 caractères.',
        ];
    }
}
