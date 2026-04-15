<?php

namespace App\Services\UserOrgRequests;

use App\Enums\RequestStatus;
use App\Models\User;
use App\Models\UserOrgRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserOrgRequestService
{
    public function create(User $user, array $data): UserOrgRequest
    {
        $this->ensureUserCanRequestOrganization($user, (int) $data['organization_id']);

        return UserOrgRequest::create([
            ...$data,
            'requested_by_user_id' => $user->id,
            'status' => RequestStatus::EN_ATTENTE,
        ])->refresh();
    }

    public function approve(UserOrgRequest $request, User $reviewer, ?int $confidenceNote = null): UserOrgRequest
    {
        $this->ensurePending($request);
        $this->ensureUserIsNotAlreadyMember($request->requestedByUser, $request->organization_id);

        return DB::transaction(function () use ($request, $reviewer, $confidenceNote): UserOrgRequest {
            $requester = $request->requestedByUser;
            $isDefault = ! $requester->organizations()->exists();

            $request->organization->users()->syncWithoutDetaching([
                $request->requested_by_user_id => [
                    'relationship_type' => $request->requested_role,
                    'is_default' => $isDefault,
                ],
            ]);

            $request->update([
                'status' => RequestStatus::APPROUVE,
                'confidence_note' => $confidenceNote,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            return $request->refresh();
        });
    }

    public function reject(UserOrgRequest $request, User $reviewer, string $reason, ?int $confidenceNote = null): UserOrgRequest
    {
        $this->ensurePending($request);

        $request->update([
            'status' => RequestStatus::REFUSE,
            'confidence_note' => $confidenceNote,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->refresh();
    }

    private function ensureUserCanRequestOrganization(User $user, int $organizationId): void
    {
        $this->ensureUserIsNotAlreadyMember($user, $organizationId);

        $hasPendingRequest = UserOrgRequest::query()
            ->where('organization_id', $organizationId)
            ->where('requested_by_user_id', $user->id)
            ->where('status', RequestStatus::EN_ATTENTE)
            ->exists();

        if ($hasPendingRequest) {
            throw ValidationException::withMessages([
                'organization_id' => 'Une demande est deja en attente pour cette organisation.',
            ]);
        }
    }

    private function ensureUserIsNotAlreadyMember(User $user, int $organizationId): void
    {
        if ($user->organizations()->whereKey($organizationId)->exists()) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cet utilisateur est deja rattache a cette organisation.',
            ]);
        }
    }

    private function ensurePending(UserOrgRequest $request): void
    {
        if (! $request->status->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Cette demande a deja ete traitee.',
            ]);
        }
    }
}
