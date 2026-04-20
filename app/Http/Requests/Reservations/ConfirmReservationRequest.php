<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return is_object($reservation)
            && ($this->user()?->can('confirm', $reservation) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
