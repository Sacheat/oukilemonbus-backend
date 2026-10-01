<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compte rendu d'un trajet. Heures réelles en HH:MM (heure locale, comme les réservations).
 * reported_by_name / reported_by_driver : qui a rempli le compte rendu (le chauffeur du trajet, ou un agent / admin) ;
 * relations driver et reportedByUser chargées par TripReportService et TripReportController.
 */
class TripReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'driver_id' => $this->driver_id,
            'outcome' => $this->outcome?->value,
            'outcome_label' => $this->outcome?->label(),
            'actual_departure_time' => $this->actual_departure_time ? substr((string) $this->actual_departure_time, 0, 5) : null,
            'actual_return_time' => $this->actual_return_time ? substr((string) $this->actual_return_time, 0, 5) : null,
            'actual_nb_children' => $this->actual_nb_children,
            'actual_nb_adults' => $this->actual_nb_adults,
            'remarks' => $this->remarks,
            'reported_by_user_id' => $this->reported_by_user_id,
            'reported_by_name' => $this->whenLoaded('reportedByUser', fn () => $this->reportedByUser
                ? trim($this->reportedByUser->first_name.' '.$this->reportedByUser->last_name)
                : null),
            'reported_by_driver' => $this->whenLoaded('driver', fn () => $this->driver !== null
                && $this->driver->user_id === $this->reported_by_user_id),
            'reported_at' => $this->reported_at?->toISOString(),
        ];
    }
}
