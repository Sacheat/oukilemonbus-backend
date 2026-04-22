<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouncilDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'decision_status' => $this->decision_status?->value ?? $this->decision_status,
            'decision_date' => $this->decision_date?->toDateString(),
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'decision_reference' => $this->decision_reference,
            'validated_at' => $this->validated_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'reservation' => new ReservationResource($this->whenLoaded('reservation')),
            'recorded_by_user' => new UserResource($this->whenLoaded('recordedByUser')),
        ];
    }
}
