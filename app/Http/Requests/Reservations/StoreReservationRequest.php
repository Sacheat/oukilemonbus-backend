<?php

namespace App\Http\Requests\Reservations;

use App\Models\Reservation;

class StoreReservationRequest extends PreviewReservationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Reservation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'requested_by_user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ];
    }
}
