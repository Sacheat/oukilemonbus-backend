<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'distance_km' => $this->distance_km,
            'estimated_duration_min' => $this->estimated_duration_min,
            'status' => $this->status,
            'approval_type' => $this->approval_type,
            'validated_by_user_id' => $this->validated_by_user_id,
            'validated_at' => $this->validated_at?->toISOString(),
            'cancelled_by_user_id' => $this->cancelled_by_user_id,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'pickup_location' => new LocationResource($this->whenLoaded('pickupLocation')),
            'destination_location' => new LocationResource($this->whenLoaded('destinationLocation')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'conflicts' => ReservationConflictResource::collection($this->whenLoaded('conflicts')),
        ];
    }
}
