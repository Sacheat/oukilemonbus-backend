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
            // Planning hebdomadaire : valeurs choisies (null = défaut) et appliquées, responsable désigné
            'weekly_planning_enabled' => $this->weekly_planning_enabled,
            'weekly_planning_send_day' => $this->weekly_planning_send_day,
            'weekly_planning_send_time' => $this->weekly_planning_send_time,
            'weekly_planning_owner_user_id' => $this->weekly_planning_owner_user_id,
            'weekly_planning_owner' => $this->whenLoaded('weeklyPlanningOwner', fn () => $this->weeklyPlanningOwner ? [
                'id' => $this->weeklyPlanningOwner->id,
                'name' => trim($this->weeklyPlanningOwner->first_name.' '.$this->weeklyPlanningOwner->last_name),
                'email' => $this->weeklyPlanningOwner->email,
                'active' => (bool) $this->weeklyPlanningOwner->active,
                // false : compte désactivé ou plus agent ni admin → il ne compte plus, tous reçoivent le planning
                'effective' => $this->weeklyPlanningOwner->canOwnWeeklyPlanning(),
            ] : null),
            'effective_weekly_planning_enabled' => (bool) ($this->weekly_planning_enabled ?? config('weekly_planning.enabled', true)),
            'effective_weekly_planning_send_day' => (int) ($this->weekly_planning_send_day ?? config('weekly_planning.send_day', 5)),
            'effective_weekly_planning_send_time' => $this->weekly_planning_send_time ?? config('weekly_planning.send_time', '12:00'),
            'effective_driver_departure_window_min' => $this->driver_departure_window_min ?? (int) config('driver_journey.departure_window_minutes', 60),
            // Composition du Collège sur les délibérations : valeurs réglées (null = défaut) et appliquées
            ...collect(['present_text', 'director_title', 'director_name', 'president_title', 'president_name', 'mayor_title', 'mayor_name', 'schools_recipient'])
                ->flatMap(fn (string $field) => [
                    "council_{$field}" => $this->{"council_{$field}"},
                    "effective_council_{$field}" => filled($this->{"council_{$field}"}) ? $this->{"council_{$field}"} : config("deliberations.council.{$field}"),
                ])
                ->all(),
            'localities' => $this->whenLoaded('localities', fn () => MunicipalityLocalityResource::collection($this->localities)),
            'active_localities' => $this->whenLoaded('activeLocalities', fn () => MunicipalityLocalityResource::collection($this->activeLocalities)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
