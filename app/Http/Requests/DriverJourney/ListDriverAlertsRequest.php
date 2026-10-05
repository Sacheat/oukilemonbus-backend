<?php

namespace App\Http\Requests\DriverJourney;

use App\Enums\DriverAlertStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de GET /api/driver-alerts : statuses[] (ouverte, prise_en_charge, cloturee), date_from / date_to (jour de
 * création à Bruxelles), per_page (1 à 100, 15 par défaut ; per_page=1 avec des statuts donne le compteur meta.total).
 */
class ListDriverAlertsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::enum(DriverAlertStatus::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'statuses.*.enum' => 'Statut de demande d’aide inconnu.',
            'date_from.date_format' => 'La date de début doit être au format AAAA-MM-JJ.',
            'date_to.date_format' => 'La date de fin doit être au format AAAA-MM-JJ.',
            'date_to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }
}
