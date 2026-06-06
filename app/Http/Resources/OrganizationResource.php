<?php

namespace App\Http\Resources;

use App\Enums\OrganizationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrganizationType|null $type */
        $type = $this->type;

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
            'reservations_used_this_year' => $this->reservations_used_this_year,
            'quota_reset_year' => $this->quota_reset_year?->toISOString(),
            'document_proof_path' => $this->document_proof_path,
            'location' => $this->whenLoaded('location', fn () => $this->location ? new LocationResource($this->location) : null),
            'users' => $this->whenLoaded('users'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
