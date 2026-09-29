<?php

namespace App\Http\Resources;

use App\Enums\RecurrenceFrequency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Resource récurrence.
 * `days_of_week` est stocké en base comme une chaîne CSV ("1,3,5") et désérialisé ici
 * en tableau d'entiers pour le frontend. Null pour les récurrences MENSUEL.
 *
 * Les infos communes à la série (organisation, lieux, horaires, public) viennent de
 * l'occurrence de référence (la première) ; status_counts et next_trip_date viennent du
 * résumé chargé par Recurrence::withSummary() / loadSummary(). Sans ce chargement,
 * ces champs sont absents de la réponse.
 */
class RecurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RecurrenceFrequency|null $frequency */
        $frequency = RecurrenceFrequency::tryFrom((string) $this->frequency);
        $summaryLoaded = $this->resource->relationLoaded('referenceReservation');
        $reference = $summaryLoaded ? $this->referenceReservation : null;

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
            'reservations_count' => $this->whenCounted('reservations'),
            $this->mergeWhen($summaryLoaded, fn () => [
                'organization'         => $reference?->organization ? [
                    'id'         => $reference->organization->id,
                    'name'       => $reference->organization->name,
                    'type'       => $reference->organization->type?->value,
                    'type_label' => $reference->organization->type?->label(),
                ] : null,
                'pickup_location'      => $reference?->pickupLocation ? new LocationResource($reference->pickupLocation) : null,
                'destination_location' => $reference?->destinationLocation ? new LocationResource($reference->destinationLocation) : null,
                'departure_time'       => $reference?->departure_time?->format('H:i'),
                'return_time'          => $reference?->return_time?->format('H:i'),
                'activity_type'        => $reference?->activity_type?->value,
                'activity_type_label'  => $reference?->activity_type?->label(),
                'trip_purpose'         => $reference?->trip_purpose,
                'nb_children'          => $reference?->nb_children,
                'nb_adults'            => $reference?->nb_adults,
                'status_counts'        => $this->resource->statusCounts(),
                'next_trip_date'       => $this->next_trip_date
                    ? Carbon::parse($this->next_trip_date)->toDateString()
                    : null,
            ]),
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
            'reservations'     => ReservationResource::collection($this->whenLoaded('reservations')),
        ];
    }
}
