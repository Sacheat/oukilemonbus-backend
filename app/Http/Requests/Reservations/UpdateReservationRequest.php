<?php

namespace App\Http\Requests\Reservations;

use App\Enums\ReservationActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return is_object($reservation)
            && ($this->user()?->can('update', $reservation) ?? false);
    }

    public function rules(): array
    {
        return [
            'pickup_location_id' => ['sometimes', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['sometimes', 'integer', 'exists:locations,id', 'different:pickup_location_id'],
            'trip_date' => ['sometimes', 'date'],
            'departure_time' => ['sometimes', 'date_format:H:i'],
            'return_time' => ['sometimes', 'date_format:H:i'],
            'nb_children' => ['sometimes', 'integer', 'min:0'],
            'nb_adults' => ['sometimes', 'integer', 'min:1'],
            'trip_purpose' => ['sometimes', 'string', 'max:500'],
            'activity_type' => ['sometimes', new Enum(ReservationActivityType::class)],
        ];
    }
}
