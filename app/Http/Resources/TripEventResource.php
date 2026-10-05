<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une étape d'un trajet. `occurred_at` en UTC (ISO), `local_time` en HH:MM à Bruxelles pour l'affichage direct.
 * Les coordonnées ne sont renvoyées qu'aux agents et admins (trip-progress.manage) ; le chauffeur voit l'état.
 */
class TripEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = config('reservations.local_timezone', 'Europe/Brussels');
        $internal = $request->user()?->can('trip-progress.manage') ?? false;

        return [
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'occurred_at' => $this->occurred_at?->toISOString(),
            'local_time' => $this->occurred_at?->setTimezone($timezone)->format('H:i'),
            'source' => $this->source->value,
            'position_state' => $this->position_state->value,
            'position_state_label' => $this->position_state->label(),
            'latitude' => $this->when($internal, $this->latitude),
            'longitude' => $this->when($internal, $this->longitude),
            'accuracy_m' => $this->when($internal, $this->accuracy_m),
        ];
    }
}
