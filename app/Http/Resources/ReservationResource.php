<?php

namespace App\Http\Resources;

use App\Services\Reservations\ReservationOccupationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource réservation.
 * `estimated_occupation` est calculé à la volée par ReservationOccupationService :
 * il inclut les buffers avant/après le trajet (pickup, dropoff, sécurité, retour).
 * Retourne null si trip_date, departure_time ou return_time est absent.
 * `council_decision_status` n'est présent que si la relation councilDecision est chargée (GET /reservations et
 * GET /reservations/{id}).
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'organization_id' => $this->organization_id,
            'pickup_location_id' => $this->pickup_location_id,
            'destination_location_id' => $this->destination_location_id,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'recurrence_id' => $this->recurrence_id,
            'trip_date' => $this->trip_date?->toDateString(),
            'departure_time' => $this->departure_time?->format('H:i:s'),
            'return_time' => $this->return_time?->format('H:i:s'),
            'nb_children' => $this->nb_children,
            'nb_adults' => $this->nb_adults,
            'trip_purpose' => $this->trip_purpose,
            'activity_type' => $this->activity_type?->value ?? $this->activity_type,
            'activity_type_label' => $this->activity_type?->label(),
            'distance_km' => $this->distance_km,
            'estimated_duration_min' => $this->estimated_duration_min,
            'estimated_occupation' => $this->estimatedOccupation(),
            'status' => $this->status,
            'status_label' => $this->status?->label(),
            // Trajet commencé (première étape du chauffeur) : figé, plus de modification, d'affectation ni d'annulation.
            // Seulement quand l'information est chargée (liste et détail des réservations) : pas de requête par ligne
            // dans les ressources imbriquées (décisions du Collège, documents, séries).
            'trip_started_at' => $this->when($this->resource->isTripStartKnown(), fn () => $this->resource->tripStartedAt()?->toISOString()),
            'trip_started_local_time' => $this->when(
                $this->resource->isTripStartKnown(),
                fn () => $this->resource->tripStartedAt()?->setTimezone(config('reservations.local_timezone', 'Europe/Brussels'))->format('H:i'),
            ),
            'approval_type' => $this->approval_type,
            'approval_type_label' => $this->approval_type?->label(),
            'validated_by_user_id' => $this->validated_by_user_id,
            'validated_at' => $this->validated_at?->toISOString(),
            'cancelled_by_user_id' => $this->cancelled_by_user_id,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'passengers_confirmed_at' => $this->passengers_confirmed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'pickup_location' => new LocationResource($this->whenLoaded('pickupLocation')),
            'destination_location' => new LocationResource($this->whenLoaded('destinationLocation')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'conflicts' => ReservationConflictResource::collection($this->whenLoaded('conflicts')),
            // Seulement si councilDecision est chargée (liste et détail) : approuve, refuse, en_attente, ou null sans décision
            'council_decision_status' => $this->whenLoaded(
                'councilDecision',
                fn () => $this->councilDecision->decision_status?->value
            ),
        ];
    }

    private function estimatedOccupation(): ?array
    {
        if (! $this->trip_date || ! $this->departure_time || ! $this->return_time) {
            return null;
        }

        $window = app(ReservationOccupationService::class)->windowForReservation($this->resource);

        return [
            'start_at' => $window['start']->format('Y-m-d\TH:i:s'),
            'end_at' => $window['end']->format('Y-m-d\TH:i:s'),
            'outbound_arrival_at' => $window['outbound_arrival']?->format('Y-m-d\TH:i:s'),
            'return_arrival_at' => $window['return_arrival']?->format('Y-m-d\TH:i:s'),
            'duration_min' => $window['duration_min'],
            'return_trip_duration_min' => $window['return_trip_duration_min'],
            'pickup_buffer_min' => $window['pickup_buffer_min'],
            'dropoff_buffer_min' => $window['dropoff_buffer_min'],
            'safety_buffer_min' => $window['safety_buffer_min'],
            'post_return_buffer_min' => $window['post_return_buffer_min'],
        ];
    }
}
