<?php

namespace App\Http\Requests\DriverJourney;

use App\Enums\TripEventType;
use App\Http\Requests\Concerns\ValidatesDevicePosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corps de POST /api/reservations/{reservation}/trip-events : l'étape, la position du téléphone, et pour la dernière
 * arrivée les passagers réels (pré-remplis avec le prévu par le front). Ordre et heures : TripProgressService.
 */
class RecordTripEventRequest extends FormRequest
{
    use ValidatesDevicePosition;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $last = TripEventType::ARRIVEE_RETOUR->value;

        return [
            'type' => ['required', Rule::enum(TripEventType::class)],
            ...$this->positionRules(),
            'actual_nb_children' => ["required_if:type,{$last}", 'nullable', 'integer', 'min:0', 'max:500'],
            'actual_nb_adults' => ["required_if:type,{$last}", 'nullable', 'integer', 'min:0', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Indiquez l’étape du trajet.',
            'type.enum' => 'Étape du trajet inconnue.',
            'actual_nb_children.required_if' => 'Indiquez le nombre d’enfants transportés.',
            'actual_nb_adults.required_if' => 'Indiquez le nombre d’adultes transportés.',
            ...$this->positionMessages(),
        ];
    }
}
