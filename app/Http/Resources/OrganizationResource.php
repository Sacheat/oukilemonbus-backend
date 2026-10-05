<?php

namespace App\Http\Resources;

use App\Enums\OrganizationType;
use App\Services\Reservations\ReservationQuotaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Organisation. `quota` : quota de l'année en cours, calculé par la même règle que le blocage
 * (ReservationQuotaService::summary), seulement quand la requête l'a compté (liste et détail des organisations :
 * OrganizationService::withListCounts) ; une organisation imbriquée dans une réservation, le planning ou une demande
 * ne le renvoie pas (pas de requête par ligne). `reservations_used_this_year` est gardé pour compatibilité : il vaut
 * quota.used quand le quota est compté (l'ancien compteur n'est plus mis à jour).
 * `rules` : ce que le type implique (priorité, approbation automatique, quota, Collège, récurrences).
 * `members_count` et `upcoming_reservations_count` : présents dans la liste et le détail (OrganizationService::withListCounts).
 */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrganizationType|null $type */
        $type = $this->type;
        $counted = isset($this->resource->quota_used_count);
        $quota = $counted
            ? app(ReservationQuotaService::class)->summary($this->resource, (int) $this->resource->quota_used_count)
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $type?->value,
            'type_label' => $type?->label(),
            'official_email' => $this->official_email,
            'phone_contact' => $this->phone_contact,
            'name_contact' => $this->name_contact,
            'location_id' => $this->location_id,
            'is_priority' => $this->is_priority,
            'is_banned' => $this->is_banned,
            'banned_reason' => $this->banned_reason,
            'annual_quota' => $this->annual_quota,
            'reservations_used_this_year' => $quota['used'] ?? $this->reservations_used_this_year,
            'quota' => $this->when($counted, $quota),
            'rules' => $type?->rules(),
            'members_count' => $this->whenCounted('members_count'),
            'upcoming_reservations_count' => $this->whenCounted('upcoming_reservations_count'),
            'quota_reset_year' => $this->quota_reset_year?->toISOString(),
            'proof_document_id' => $this->proof_document_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? new LocationResource($this->location) : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
