<?php

namespace App\Http\Resources;

use App\Services\Planning\ReservationTimeWindowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanningItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$occupationStart, $occupationEnd] = app(ReservationTimeWindowService::class)->window($this->resource);
        $availability = $this->resource->getAttribute('planning_availability');

        return [
            'reservation_id' => $this->id,
            'trip_date' => $this->trip_date?->toDateString(),
            'departure_time' => $this->departure_time?->format('H:i:s'),
            'return_time' => $this->return_time?->format('H:i:s'),
            'occupation_start' => $occupationStart->format('Y-m-d H:i:s'),
            'occupation_end' => $occupationEnd->format('Y-m-d H:i:s'),
            'status' => $this->status?->value ?? $this->status,
            'approval_type' => $this->approval_type?->value ?? $this->approval_type,
            'total_passengers' => $this->nb_children + $this->nb_adults,
            'is_fully_assigned' => filled($this->driver_id) && filled($this->vehicle_id),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'pickup_location' => new LocationResource($this->whenLoaded('pickupLocation')),
            'destination_location' => new LocationResource($this->whenLoaded('destinationLocation')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'conflicts' => ReservationConflictResource::collection($this->whenLoaded('conflicts')),
            'availability' => $this->when($availability !== null, $availability),
        ];
    }
}
