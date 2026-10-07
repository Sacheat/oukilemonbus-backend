<?php

namespace App\Http\Requests\DriverJourney;

use App\Enums\TripEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DELETE /api/reservations/{reservation}/trip-events/last?type=… : l'étape que le front veut annuler (celle qu'il
 * affiche comme dernière). Obligatoire : un double clic ne doit pas effacer l'étape d'avant (TripProgressService).
 */
class CancelTripEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TripEventType::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Indiquez l’étape à annuler.',
            'type.enum' => 'Étape du trajet inconnue.',
        ];
    }
}
