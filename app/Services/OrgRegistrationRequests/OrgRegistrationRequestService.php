<?php

namespace App\Services\OrgRegistrationRequests;

use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrgRegistrationRequestService
{
    public function __construct(
        private readonly OrgRegistrationConfidenceScoreService $confidenceScoreService
    ) {}

    public function create(User $user, array $data): OrgRegistrationRequest
    {
        $request = OrgRegistrationRequest::create([
            ...$data,
            'request_by_user_id' => $user->id,
            'status' => RequestStatus::EN_ATTENTE,
        ])->refresh();

        $request->load(['requestedByUser', 'location']);
        $request->update([
            'confidence_note' => $this->confidenceScoreService->calculate($request),
        ]);

        return $request->refresh();
    }

    public function approve(OrgRegistrationRequest $request, User $reviewer): Organization
    {
        $this->ensurePending($request);

        return DB::transaction(function () use ($request, $reviewer): Organization {
            $organizationType = $request->organization_type;

            $organization = Organization::create([
                'name' => $request->organization_name,
                'type' => $organizationType,
                'official_email' => $request->official_email,
                'phone_contact' => $request->phone,
                'name_contact' => $request->contact_person_name,
                'location_id' => $request->location_id,
                'is_priority' => $organizationType->isPriorityByDefault(),
                'document_proof_path' => $request->proof_document_path,
            ])->refresh();

            $organization->users()->syncWithoutDetaching([
                $request->request_by_user_id => [
                    'relationship_type' => 'responsable',
                    'is_default' => true,
                ],
            ]);

            $request->update([
                'status' => RequestStatus::APPROUVE,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            return $organization;
        });
    }

    public function reject(OrgRegistrationRequest $request, User $reviewer, string $reason): OrgRegistrationRequest
    {
        $this->ensurePending($request);

        $request->update([
            'status' => RequestStatus::REFUSE,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->refresh();
    }

    private function ensurePending(OrgRegistrationRequest $request): void
    {
        if (! $request->status->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Cette demande a deja ete traitee.',
            ]);
        }
    }
}
