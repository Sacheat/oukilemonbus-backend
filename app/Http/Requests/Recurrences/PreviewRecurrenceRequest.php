<?php

namespace App\Http\Requests\Recurrences;

use App\Enums\ReservationActivityType;
use App\Enums\RecurrenceFrequency;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class PreviewRecurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reservations.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_id'          => ['required', 'integer', 'exists:organizations,id'],
            'pickup_location_id'        => ['required', 'integer', 'exists:locations,id'],
            'destination_location_id'   => ['required', 'integer', 'exists:locations,id', 'different:pickup_location_id'],
            'departure_time'            => ['required', 'date_format:H:i'],
            'return_time'               => ['required', 'date_format:H:i', 'after:departure_time'],
            'nb_children'               => ['required', 'integer', 'min:0'],
            'nb_adults'                 => ['required', 'integer', 'min:1'],
            'trip_purpose'              => ['required', 'string', 'max:500'],
            'activity_type'             => ['sometimes', new Enum(ReservationActivityType::class)],
            'frequency'                 => ['required', new Enum(RecurrenceFrequency::class)],
            'days_of_week'              => ['required_unless:frequency,mensuel', 'nullable', 'array', 'min:1', 'distinct'],
            'days_of_week.*'            => ['integer', 'min:1', 'max:5'],
            'start_date'                => ['required', 'date', 'after_or_equal:today'],
            'end_date'                  => ['required', 'date', 'after:start_date'],
        ];
    }
}