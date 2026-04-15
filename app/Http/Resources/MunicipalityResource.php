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
            'best_id' => $this->best_id,
            'nis_code' => $this->nis_code,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'source' => $this->source,
            'is_active' => $this->is_active,
            'synced_at' => $this->synced_at?->toISOString(),
            'localities' => $this->whenLoaded('localities', fn () => MunicipalityLocalityResource::collection($this->localities)),
            'active_localities' => $this->whenLoaded('activeLocalities', fn () => MunicipalityLocalityResource::collection($this->activeLocalities)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
