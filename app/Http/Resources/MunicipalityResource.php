<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MunicipalityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'external_id' => $this->external_id,
            'nis_code' => $this->nis_code,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'source' => $this->source,
            'is_active' => $this->is_active,
            'synced_at' => $this->synced_at?->toISOString(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'depot_location_id' => $this->depot_location_id,
            'depot_location' => $this->whenLoaded('depotLocation', fn () => $this->depotLocation ? new LocationResource($this->depotLocation) : null),
            // Valeurs configurées (null = défaut) et valeurs réellement appliquées aux réservations.
            'max_trip_distance_km' => $this->max_trip_distance_km,
            'max_pickup_distance_km' => $this->max_pickup_distance_km,
            'effective_max_trip_distance_km' => $this->max_trip_distance_km ?? (int) config('reservations.max_distance_km', 50),
            'effective_max_pickup_distance_km' => $this->max_pickup_distance_km ?? (int) config('reservations.max_pickup_distance_km', 50),
            'localities' => $this->whenLoaded('localities', fn () => MunicipalityLocalityResource::collection($this->localities)),
            'active_localities' => $this->whenLoaded('activeLocalities', fn () => MunicipalityLocalityResource::collection($this->activeLocalities)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
