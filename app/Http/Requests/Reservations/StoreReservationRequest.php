<?php

namespace App\Http\Requests\Reservations;

use App\Models\Reservation;
use Illuminate\Support\Arr;

class StoreReservationRequest extends PreviewReservationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Reservation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // reservation_id ne concerne que la vérification d'une modification (preview, check-trip).
            ...Arr::except(parent::rules(), ['reservation_id']),
            'requested_by_user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ];
    }
}
