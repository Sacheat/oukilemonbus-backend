<?php

namespace App\Http\Resources;

use App\Enums\ReservationStatus;
use App\Services\Reservations\ReservationOccupationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource planning. Enrichit chaque réservation avec :
 * - occupation : fenêtre complète calculée par ReservationOccupationService (buffers inclus)
 * - display : titre, libellé horaire, statut, affectation — prêts à l'emploi pour le frontend
 * - availability : attribut personnalisé injecté par PlanningService, présent seulement si
 *   `include_availability` est demandé (admin/agent uniquement)
 */
class PlanningItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $occupation = app(ReservationOccupationService::class)->windowForReservation($this->resource);
        // Attribut dynamique injecté par PlanningService::forPeriod() quand include_availability=true.
        $availability = $this->resource->getAttribute('planning_availability');

        return [
            'reservation_id' => $this->id,
            'trip_date' => $this->trip_date?->toDateString(),
            'departure_time' => $this->departure_time?->format('H:i:s'),
            'return_time' => $this->return_time?->format('H:i:s'),
            'occupation_start' => $occupation['start']->format('Y-m-d H:i:s'),
            'occupation_end' => $occupation['end']->format('Y-m-d H:i:s'),
            'occupation_duration_min' => $occupation['duration_min'],
            'status' => $this->status?->value ?? $this->status,
            'approval_type' => $this->approval_type?->value ?? $this->approval_type,
            'total_passengers' => ($this->nb_children ?? 0) + ($this->nb_adults ?? 0),
            'is_fully_assigned' => filled($this->driver_id) && filled($this->vehicle_id),
            'display' => $this->displayData($occupation),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'pickup_location' => new LocationResource($this->whenLoaded('pickupLocation')),
            'destination_location' => new LocationResource($this->whenLoaded('destinationLocation')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'conflicts' => ReservationConflictResource::collection($this->whenLoaded('conflicts')),
            'availability' => $this->when($availability !== null, $availability),
        ];
    }

    private function displayData(array $occupation): array
    {
        return [
            'title' => $this->displayTitle(),
            'time_label' => $occupation['start']->format('H:i').' - '.$occupation['end']->format('H:i'),
            'status_label' => $this->statusLabel(),
            'assignment_label' => $this->assignmentLabel(),
            'color' => $this->statusColor(),
        ];
    }

    private function displayTitle(): string
    {
        $parts = array_filter([
            $this->organization?->name,
            $this->trip_purpose,
        ]);

        if ($parts !== []) {
            return implode(' - ', $parts);
        }

        return "Réservation #{$this->id}";
    }

    private function assignmentLabel(): string
    {
        if (filled($this->driver_id) && filled($this->vehicle_id)) {
            return 'Affecte';
        }

        if (filled($this->driver_id) || filled($this->vehicle_id)) {
            return 'Partiellement affecté';
        }

        return 'Non affecté';
    }

    private function statusLabel(): string
    {
        return $this->reservationStatus()?->label() ?? (string) ($this->status?->value ?? $this->status);
    }

    private function statusColor(): string
    {
        return $this->reservationStatus()?->color() ?? 'secondary';
    }

    private function reservationStatus(): ?ReservationStatus
    {
        if ($this->status instanceof ReservationStatus) {
            return $this->status;
        }

        if (is_string($this->status)) {
            return ReservationStatus::tryFrom($this->status);
        }

        return null;
    }
}
