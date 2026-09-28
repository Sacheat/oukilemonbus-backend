<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationConflictResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'type' => $this->type,
            'type_label' => $this->type?->label(),
            'message' => $this->message,
            'related_reservation_id' => $this->related_reservation_id,
            'is_resolved' => $this->is_resolved,
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
