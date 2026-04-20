<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Foundation\Http\FormRequest;

class AssignReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return is_object($reservation)
            && ($this->user()?->can('assign', $reservation) ?? false);
    }

    public function rules(): array
    {
        return [
            'driver_id' => ['sometimes', 'integer', 'exists:drivers,id'],
            'vehicle_id' => ['sometimes', 'integer', 'exists:vehicles,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
