<?php

namespace App\Http\Requests\Planning;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
                        $fail('La periode du planning ne peut pas depasser 31 jours.');
                    }
                },
            ],
            'include_availability' => ['sometimes', 'boolean'],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['string', Rule::in(array_column(ReservationStatus::cases(), 'value'))],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'La date de debut est obligatoire.',
            'start_date.date_format' => 'La date de debut doit etre au format YYYY-MM-DD.',
            'end_date.required' => 'La date de fin est obligatoire.',
            'end_date.date_format' => 'La date de fin doit etre au format YYYY-MM-DD.',
            'end_date.after_or_equal' => 'La date de fin doit etre egale ou posterieure a la date de debut.',
            'statuses.*.in' => 'Un des statuts demandes est invalide.',
        ];
    }
}
