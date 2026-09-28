<?php

namespace App\Http\Requests\Planning;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requête de consultation du planning.
 * Limite la période à 31 jours via une règle closure (performances de requête).
 * Expose des méthodes helpers utilisées par PlanningController :
 * `planningStatuses()`, `wantsAvailabilityDetails()`, `planningFilters()`.
 */
class PlanningIndexRequest extends FormRequest
{
    private const MAX_PERIOD_DAYS = 31;

    public function authorize(): bool
    {
        return $this->user()?->can('reservations.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $startDate = $this->input('start_date');

                    if (! is_string($startDate) || ! is_string($value)) {
                        return;
                    }

                    try {
                        $start = CarbonImmutable::createFromFormat('Y-m-d', $startDate)->startOfDay();
                        $end = CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
                    } catch (\Throwable) {
                        return;
                    }

                    if ($end->lessThan($start)) {
                        return;
                    }

                    if ($start->diffInDays($end) > self::MAX_PERIOD_DAYS - 1) {
                        $fail('La période du planning ne peut pas dépasser 31 jours.');
                    }
                },
            ],
            'include_availability' => ['sometimes', 'boolean'],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['string', Rule::in(array_column(ReservationStatus::cases(), 'value'))],

            // Filtres optionnels de consultation du planning.
            // Ces filtres sont utilisés par l'API planning et par l'export.
            'vehicle_id' => ['sometimes', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['sometimes', 'integer', 'exists:drivers,id'],
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'La date de début est obligatoire.',
            'start_date.date_format' => 'La date de début doit être au format YYYY-MM-DD.',
            'end_date.required' => 'La date de fin est obligatoire.',
            'end_date.date_format' => 'La date de fin doit être au format YYYY-MM-DD.',
            'end_date.after_or_equal' => 'La date de fin doit être égale ou postérieure à la date de début.',
            'statuses.*.in' => 'Un des statuts demandes est invalide.',
            'vehicle_id.exists' => 'Le véhicule demandé est introuvable.',
            'driver_id.exists' => 'Le chauffeur demandé est introuvable.',
            'organization_id.exists' => 'L’organisation demandée est introuvable.',
        ];
    }

    public function planningStatuses(): array
    {
        return $this->validated('statuses', []);
    }

    public function wantsAvailabilityDetails(): bool
    {
        return $this->boolean('include_availability');
    }

    public function planningFilters(): array
    {
        return array_filter([
            'vehicle_id' => $this->has('vehicle_id') ? $this->integer('vehicle_id') : null,
            'driver_id' => $this->has('driver_id') ? $this->integer('driver_id') : null,
            'organization_id' => $this->has('organization_id') ? $this->integer('organization_id') : null,
        ], fn ($value): bool => $value !== null);
    }
}
