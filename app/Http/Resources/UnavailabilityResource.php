<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnavailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'reason_type'         => $this->reason_type,
            'reason'              => $this->reason,
            'driver_id'           => $this->driver_id,
            'vehicle_id'          => $this->vehicle_id,
            'start_datetime'      => $this->start_datetime?->toISOString(),
            'end_datetime'        => $this->end_datetime?->toISOString(),
            'created_by_user_id'  => $this->created_by_user_id,
            'created_at'          => $this->created_at?->toISOString(),
            'updated_at'          => $this->updated_at?->toISOString(),
            'is_active'           => $this->is_active,
        ];
    }
}
