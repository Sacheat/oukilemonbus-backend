<?php

namespace App\Http\Resources;

use App\Enums\RecurrenceFrequency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RecurrenceFrequency|null $frequency */
        $frequency = RecurrenceFrequency::tryFrom((string) $this->frequency);

        return [
            'id'               => $this->id,
            'frequency'        => $this->frequency,
            'frequency_label'  => $frequency?->label(),
            'interval_value'   => $this->interval_value,
            'days_of_week'     => $this->days_of_week
                ? array_map('intval', explode(',', (string) $this->days_of_week))
                : null,
            'start_date'       => $this->start_date?->toDateString(),
            'end_date'         => $this->end_date?->toDateString(),
            'occurrence_count' => $this->occurrence_count,
            'is_active'        => $this->is_active,
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
            'reservations'     => ReservationResource::collection($this->whenLoaded('reservations')),
        ];
    }
}
