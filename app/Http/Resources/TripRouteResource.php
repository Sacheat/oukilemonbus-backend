<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tracé réel d'un trajet (aller : lieu de départ → destination). `geometry` en [[lat, lng], …] (ordre de Leaflet),
 * `steps` : instructions en français ({ instruction, name, distance_m, duration_s }).
 */
class TripRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reservation_id' => $this->reservation_id,
            'geometry' => $this->geometry,
            'distance_m' => $this->distance_m,
            'duration_s' => $this->duration_s,
            'steps' => $this->steps,
            'provider' => $this->provider,
            'computed_at' => $this->computed_at?->toISOString(),
        ];
    }
}
