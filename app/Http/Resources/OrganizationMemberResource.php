<?php

namespace App\Http\Resources;

use App\Models\UserOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membre d'une organisation : identité de l'utilisateur + sa relation avec l'organisation (pivot).
 */
class OrganizationMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $relationshipType = $this->pivot?->relationship_type;

        return [
            'user_id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'email' => $this->email,
            'phone' => $this->phone,
            'relationship_type' => $relationshipType,
            'is_manager' => in_array($relationshipType, UserOrganization::MANAGER_TYPES, true),
            'is_default' => (bool) $this->pivot?->is_default,
            'joined_at' => $this->pivot?->created_at?->toISOString(),
        ];
    }
}
