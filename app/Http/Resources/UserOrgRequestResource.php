<?php

namespace App\Http\Resources;

use App\Enums\RequestStatus;
use App\Services\UserOrgRequests\UserOrgRequestConfidenceScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserOrgRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RequestStatus|null $status */
        $status = $this->status;

        return [
            'request_id' => $this->request_id,
            'organization_id' => $this->organization_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'requested_role' => $this->requested_role,
            'justification' => $this->justification,
            'proof_document_path' => $this->proof_document_path,
            'status' => $status?->value,
            'status_label' => $status?->label(),
            'confidence_note' => $this->confidence_note,
            'confidence_details' => app(UserOrgRequestConfidenceScoreService::class)->explain($this->resource),
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'organization' => $this->whenLoaded('organization', fn () => new OrganizationResource($this->organization)),
            'requested_by_user' => $this->whenLoaded('requestedByUser', fn () => new UserResource($this->requestedByUser)),
            'reviewed_by_user' => $this->whenLoaded('reviewedByUser', fn () => $this->reviewedByUser ? new UserResource($this->reviewedByUser) : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
