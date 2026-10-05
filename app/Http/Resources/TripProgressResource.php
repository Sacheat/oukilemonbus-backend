<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Avancement d'un trajet (résumé de TripProgressService::summary) : état, étape suivante, étapes enregistrées.
 */
class TripProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reservation_id' => $this->resource['reservation_id'],
            'state' => $this->resource['state']->value,
            'state_label' => $this->resource['state']->label(),
            'next_step' => $this->resource['next_step']?->value,
            'next_step_label' => $this->resource['next_step']?->label(),
            'events' => TripEventResource::collection($this->resource['events']),
        ];
    }
}
