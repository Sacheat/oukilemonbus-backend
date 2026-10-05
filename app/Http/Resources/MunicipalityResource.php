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
            // Contact de garde (facultatif) : reçoit par email les demandes d'aide des chauffeurs
            'on_call_name' => $this->on_call_name,
            'on_call_email' => $this->on_call_email,
            'on_call_phone' => $this->on_call_phone,
            // « Ma journée » du chauffeur : valeur choisie (null = défaut) et valeur appliquée
            'navigation_engine' => $this->navigation_engine,
            'driver_departure_window_min' => $this->driver_departure_window_min,
            'effective_navigation_engine' => isset(config('driver_journey.navigation_engines', [])[$this->navigation_engine ?? ''])
                ? $this->navigation_engine
                : config('driver_journey.default_navigation_engine', 'openstreetmap'),
            'effective_driver_departure_window_min' => $this->driver_departure_window_min ?? (int) config('driver_journey.departure_window_minutes', 60),
            'localities' => $this->whenLoaded('localities', fn () => MunicipalityLocalityResource::collection($this->localities)),
            'active_localities' => $this->whenLoaded('activeLocalities', fn () => MunicipalityLocalityResource::collection($this->activeLocalities)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
