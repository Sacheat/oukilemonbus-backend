<?php

namespace App\Http\Resources;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Services\OrgRegistrationRequests\OrgRegistrationConfidenceScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource demande d'inscription organisation.
 * `confidence_details` est calculé à chaque appel par OrgRegistrationConfidenceScoreService::explain()
 * et n'est pas stocké en base — il détaille les critères ayant contribué au score `confidence_note`.
 */
class OrgRegistrationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrganizationType|null $organizationType */
        $organizationType = $this->organization_type;

        /** @var RequestStatus|null $status */
        $status = $this->status;

        return [
            'id' => $this->id,
            'request_by_user_id' => $this->request_by_user_id,
            'organization_name' => $this->organization_name,
            'organization_type' => $organizationType?->value,
            'organization_type_label' => $organizationType?->label(),
            'enterprise_number' => $this->enterprise_number,
            'official_email' => $this->official_email,
            'phone' => $this->phone,
            'location_id' => $this->location_id,
            'contact_person_name' => $this->contact_person_name,
            'contact_person_role' => $this->contact_person_role,
            'proof_document_id' => $this->proof_document_id,
            'proof_document' => $this->whenLoaded('proofDocument', fn () => $this->proofDocument ? new DocumentResource($this->proofDocument) : null),
            'comment' => $this->comment,
            'status' => $status?->value,
            'status_label' => $status?->label(),
            'confidence_note' => $this->confidence_note,
            'confidence_details' => app(OrgRegistrationConfidenceScoreService::class)->explain($this->resource),
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'requested_by_user' => $this->whenLoaded('requestedByUser', fn () => new UserResource($this->requestedByUser)),
            'reviewed_by_user' => $this->whenLoaded('reviewedByUser', fn () => $this->reviewedByUser ? new UserResource($this->reviewedByUser) : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? new LocationResource($this->location) : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
