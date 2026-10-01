<?php

namespace App\Http\Requests\Reservations;

use App\Enums\TripReportOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corps de PUT /api/reservations/{reservation}/trip-report.
 * Réalisé : heures réelles et passagers réels obligatoires ; non réalisé : motif (remarks) obligatoire.
 * Qui et quand : TripReportService (le contrôle d'accès n'est pas fait ici).
 */
class SaveTripReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $realised = TripReportOutcome::REALISE->value;
        $notRealised = TripReportOutcome::NON_REALISE->value;

        return [
            'outcome' => ['required', Rule::enum(TripReportOutcome::class)],
            'actual_departure_time' => ["required_if:outcome,{$realised}", 'nullable', 'date_format:H:i'],
            'actual_return_time' => ["required_if:outcome,{$realised}", 'nullable', 'date_format:H:i', 'after:actual_departure_time'],
            'actual_nb_children' => ["required_if:outcome,{$realised}", 'nullable', 'integer', 'min:0', 'max:500'],
            'actual_nb_adults' => ["required_if:outcome,{$realised}", 'nullable', 'integer', 'min:0', 'max:500'],
            'remarks' => ["required_if:outcome,{$notRealised}", 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required' => 'Indiquez si le trajet a été réalisé.',
            'outcome.enum' => 'Le résultat doit valoir realise ou non_realise.',
            'actual_departure_time.required_if' => 'Indiquez l’heure réelle de départ.',
            'actual_departure_time.date_format' => 'L’heure de départ doit être au format HH:MM.',
            'actual_return_time.required_if' => 'Indiquez l’heure réelle de retour.',
            'actual_return_time.date_format' => 'L’heure de retour doit être au format HH:MM.',
            'actual_return_time.after' => 'L’heure de retour doit être après l’heure de départ.',
            'actual_nb_children.required_if' => 'Indiquez le nombre réel d’enfants (0 s’il n’y en avait pas).',
            'actual_nb_adults.required_if' => 'Indiquez le nombre réel d’adultes (0 s’il n’y en avait pas).',
            'actual_nb_children.min' => 'Le nombre d’enfants ne peut pas être négatif.',
            'actual_nb_adults.min' => 'Le nombre d’adultes ne peut pas être négatif.',
            'remarks.required_if' => 'Expliquez pourquoi le trajet n’a pas été réalisé.',
            'remarks.max' => 'La remarque ne peut pas dépasser 1000 caractères.',
        ];
    }
}
