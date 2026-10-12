<?php

namespace App\Http\Resources;

use App\Casts\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnavailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason_type' => $this->reason_type,
            'reason' => $this->reason,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            // Ce qui est indisponible, en toutes lettres (aussi pour un chauffeur ou un bus désactivé depuis)
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id,
                'name' => trim(($this->driver->user?->first_name ?? '').' '.($this->driver->user?->last_name ?? '')),
                'is_active' => (bool) $this->driver->is_active,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'licence_plate' => $this->vehicle->licence_plate,
                'is_active' => (bool) $this->vehicle->is_active,
            ] : null),
            // Heure locale de Bruxelles, sans fuseau : ne pas convertir depuis l'UTC côté front.
            'start_datetime' => $this->start_datetime?->format(LocalDateTime::API_FORMAT),
            'end_datetime' => $this->end_datetime?->format(LocalDateTime::API_FORMAT),
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'is_active' => $this->is_active,
        ];
    }
}
