<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPassengersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return is_object($reservation)
            && ($this->user()?->can('confirmPassengers', $reservation) ?? false);
    }

    public function rules(): array
    {
        return [
            'nb_children' => ['sometimes', 'integer', 'min:0'],
            'nb_adults'   => ['sometimes', 'integer', 'min:1'],
            'trip_purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}