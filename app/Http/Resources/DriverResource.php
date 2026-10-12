<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RGPD, minimisation : le permis et l'email ne sont renvoyés qu'aux rôles internes (admin, agent) et au chauffeur
 * lui-même. Les autres (membres d'organisation, autres chauffeurs) voient le nom et le téléphone, assez pour le
 * joindre le jour du trajet.
 */
class DriverResource extends JsonResource
{
    private const array FULL_VIEW_ROLES = ['admin', 'agent_communal'];

    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $fullView = $viewer !== null
            && ($viewer->getKey() === $this->user_id || $viewer->hasAnyRole(self::FULL_VIEW_ROLES));

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => match (true) {
                $this->user === null => null,
                $fullView => new UserResource($this->user),
                default => [
                    'id' => $this->user->id,
                    'first_name' => $this->user->first_name,
                    'last_name' => $this->user->last_name,
                    'full_name' => trim($this->user->first_name.' '.$this->user->last_name),
                    'phone' => $this->user->phone,
                ],
            }),
            'licence_number' => $this->when($fullView, $this->licence_number),
            'licence_expiry_date' => $this->when($fullView, fn () => $this->licence_expiry_date?->toDateString()),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
