<?php

namespace App\Http\Resources;

use App\Casts\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnavailabilityHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'unavailability_id'   => $this->unavailability_id,
            'old_reason_type'     => $this->old_reason_type,
            'new_reason_type'     => $this->new_reason_type,
            'old_start_datetime'  => $this->old_start_datetime?->format(LocalDateTime::API_FORMAT),
            'new_start_datetime'  => $this->new_start_datetime?->format(LocalDateTime::API_FORMAT),
            'old_end_datetime'    => $this->old_end_datetime?->format(LocalDateTime::API_FORMAT),
            'new_end_datetime'    => $this->new_end_datetime?->format(LocalDateTime::API_FORMAT),
            'old_reason'          => $this->old_reason,
            'new_reason'          => $this->new_reason,
            'changed_by_user_id'  => $this->changed_by_user_id,
            'change_reason'       => $this->change_reason,
            'created_at'          => $this->created_at?->toISOString(),
        ];
    }
}
