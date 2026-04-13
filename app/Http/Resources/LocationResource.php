<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'formatted_address' => $this->formatted_address,
            'street' => $this->street,
            'street_number' => $this->street_number,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'place_id' => $this->place_id,
            'provider' => $this->provider,
            'is_verified' => $this->is_verified,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
