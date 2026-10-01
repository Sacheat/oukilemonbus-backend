<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Feuille de route officielle (journalière, par véhicule) dans GET /route-sheets.
 * Téléchargement : GET /documents/{id}/download. own_trip_count : missions du chauffeur connecté (null sinon).
 */
class RouteSheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_date' => $this->service_date?->toDateString(),
            'vehicle' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'licence_plate' => $this->vehicle->licence_plate,
                'capacity' => $this->vehicle->capacity,
            ] : null,
            'version' => $this->version,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'generated_at' => $this->generated_at?->toISOString(),
            'trip_count' => $this->trip_count,
            'own_trip_count' => $this->own_trip_count ?? null,
        ];
    }
}
