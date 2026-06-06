<?php

namespace App\Http\Requests\Reservations;

use App\Enums\ReservationActivityType;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Requête de prévisualisation de réservation.
 * Sert de classe de base pour StoreReservationRequest qui hérite de ces règles
 * et y ajoute le champ optionnel `requested_by_user_id`.
 */
class PreviewReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('preview', Reservation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'pickup_location_id' => ['required', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id', 'different:pickup_location_id'],
            'trip_date' => ['required', 'date'],
            'departure_time' => ['required', 'date_format:H:i'],
            'return_time' => ['required', 'date_format:H:i', 'after:departure_time'],
            'nb_children' => ['required', 'integer', 'min:0'],
            'nb_adults' => ['required', 'integer', 'min:1'],
            'trip_purpose' => ['required', 'string', 'max:500'],
            'activity_type' => ['sometimes', new Enum(ReservationActivityType::class)],
        ];
    }
}
