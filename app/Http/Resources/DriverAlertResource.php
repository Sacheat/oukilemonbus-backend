<?php

namespace App\Http\Resources;

use App\Services\DriverAlerts\DriverAlertService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demande d'aide d'un chauffeur : position (ou dernier lieu connu), trajet, chauffeur, véhicule, suivi par un agent,
 * et liens vers une carte publique. Horodatages en UTC (ISO).
 */
class DriverAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(DriverAlertService::class);
        $reservation = $this->reservation;
        $driverUser = $this->driver?->user;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'message' => $this->message,
            'created_at' => $this->created_at?->toISOString(),
            'trip_progress' => $this->trip_progress?->value,
            'trip_progress_label' => $this->trip_progress?->label(),
            'position' => [
                'state' => $this->position_state->value,
                'state_label' => $this->position_state->label(),
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'accuracy_m' => $this->accuracy_m,
                'captured_at' => $this->position_captured_at?->toISOString(),
                'updates' => $this->position_updates,
            ],
            'fallback_location' => $this->fallback_label === null ? null : [
                'label' => $this->fallback_label,
                'latitude' => $this->fallback_latitude,
                'longitude' => $this->fallback_longitude,
            ],
            'map_point' => $this->mapPoint(),
            'map_links' => $service->mapLinks($this->resource),
            'summary' => $service->alertSummary($this->resource),
            'reservation' => $reservation === null ? null : [
                'id' => $reservation->id,
                'trip_date' => $reservation->trip_date?->toDateString(),
                'departure_time' => $reservation->departure_time?->format('H:i'),
                'return_time' => $reservation->return_time?->format('H:i'),
                'passengers' => (int) $reservation->nb_children + (int) $reservation->nb_adults,
                'organization' => $reservation->organization?->name,
                'organization_phone' => $reservation->organization?->phone_contact,
                'pickup' => $reservation->pickupLocation ? [
                    'address' => $reservation->pickupLocation->formatted_address,
                    'latitude' => $reservation->pickupLocation->latitude !== null ? (float) $reservation->pickupLocation->latitude : null,
                    'longitude' => $reservation->pickupLocation->longitude !== null ? (float) $reservation->pickupLocation->longitude : null,
                ] : null,
                'destination' => $reservation->destinationLocation ? [
                    'address' => $reservation->destinationLocation->formatted_address,
                    'latitude' => $reservation->destinationLocation->latitude !== null ? (float) $reservation->destinationLocation->latitude : null,
                    'longitude' => $reservation->destinationLocation->longitude !== null ? (float) $reservation->destinationLocation->longitude : null,
                ] : null,
            ],
            'driver' => [
                'id' => $this->driver_id,
                'name' => $driverUser ? trim($driverUser->first_name.' '.$driverUser->last_name) : null,
                'phone' => $driverUser?->phone,
            ],
            'vehicle' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'licence_plate' => $this->vehicle->licence_plate,
                'capacity' => $this->vehicle->capacity,
            ] : null,
            'handled_by' => $this->handledByUser ? trim($this->handledByUser->first_name.' '.$this->handledByUser->last_name) : null,
            'handled_at' => $this->handled_at?->toISOString(),
            // Relance automatique (driver-alerts:remind) si personne ne l'a prise en charge à temps
            'reminded_at' => $this->reminded_at?->toISOString(),
            'closed_by' => $this->closedByUser ? trim($this->closedByUser->first_name.' '.$this->closedByUser->last_name) : null,
            'closed_at' => $this->closed_at?->toISOString(),
            'closing_note' => $this->closing_note,
        ];
    }
}
