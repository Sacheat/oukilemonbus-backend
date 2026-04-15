<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MunicipalityLocalityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'municipality_id' => $this->municipality_id,
            'name' => $this->name,
            'normalized_name' => $this->normalized_name,
            'best_id' => $this->best_id,
            'source' => $this->source,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
