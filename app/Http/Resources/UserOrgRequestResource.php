<?php

namespace App\Http\Resources;

use App\Enums\RequestStatus;
use App\Services\UserOrgRequests\UserOrgRequestConfidenceScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource demande de rattachement utilisateur-organisation.
 * `confidence_details` est calculé à chaque appel par UserOrgRequestConfidenceScoreService::explain()
 * et n'est pas stocké en base — il détaille les critères du score. Voir docs/organizations.md (scoring de confiance).
 */
class UserOrgRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RequestStatus|null $status */
        $status = $this->status;

        // Demande en attente : note et détail sont le même calcul (le tri s'appuie sur la note enregistrée, tenue à jour
        // par les services). Demande traitée : la note de la décision est figée ; le détail est recalculé à titre indicatif.
        $confidence = app(UserOrgRequestConfidenceScoreService::class)->explain($this->resource);

        return [
            'request_id' => $this->request_id,
            'organization_id' => $this->organization_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'requested_role' => $this->requested_role,
            'justification' => $this->justification,
            'proof_document_id' => $this->proof_document_id,
            'proof_document' => $this->whenLoaded('proofDocument', fn () => $this->proofDocument ? new DocumentResource($this->proofDocument) : null),
            'status' => $status?->value,
            'status_label' => $status?->label(),
            'confidence_note' => $status?->isPending() ? $confidence['score'] : $this->confidence_note,
            'confidence_details' => $confidence,
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
