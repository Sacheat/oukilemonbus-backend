<?php

namespace App\Http\Requests\Reservations;

use App\Enums\ReservationActivityType;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Vérification du trajet seul (POST /api/reservations/check-trip) : mêmes champs que le preview,
 * sans les passagers ni le motif, pour valider l'étape 1 du formulaire en direct.
 * `reservation_id` : vérification d'une modification (la réservation est exclue des calculs).
 */
class CheckTripRequest extends FormRequest
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
            'activity_type' => ['sometimes', new Enum(ReservationActivityType::class)],
            // Modification : réservation à exclure des calculs (chevauchement, quota), cf. ReservationController::previewPayload().
            'reservation_id' => ['sometimes', 'integer', 'exists:reservations,id'],
        ];
    }
}
