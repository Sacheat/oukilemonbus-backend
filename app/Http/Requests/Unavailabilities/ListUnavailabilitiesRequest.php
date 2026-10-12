<?php

namespace App\Http\Requests\Unavailabilities;

use App\Models\Unavailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de GET /unavailabilities (tous facultatifs) :
 *   period     a_venir (en cours ou à venir) | passees | annulees : un onglet de la page, trié et paginé par le backend,
 *              avec les nombres des trois onglets dans meta.period_counts
 *   driver_id, vehicle_id : une seule ressource
 *   current=1  seulement celles qui s'appliquent maintenant (état du jour des véhicules et chauffeurs)
 *   per_page   1 à 100 (15 par défaut)
 * Sans `period`, la liste reste celle d'avant : toutes, triées par début.
 */
class ListUnavailabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('viewAny') dans le contrôleur
    }

    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'nullable', Rule::in(Unavailability::PERIODS)],
            'driver_id' => ['sometimes', 'nullable', 'integer'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'current' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'period.in' => 'Onglet inconnu : choisissez en cours et à venir, passées ou annulées.',
            'per_page.max' => 'Au plus 100 indisponibilités par page.',
        ];
    }
}
