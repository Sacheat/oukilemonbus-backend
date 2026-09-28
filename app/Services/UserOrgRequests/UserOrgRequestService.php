<?php

namespace App\Services\UserOrgRequests;

use App\Enums\RequestStatus;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Services\Documents\ProofDocumentService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère le cycle de vie des demandes d'adhésion à une organisation.
 *
 * `create()` : vérifie qu'aucune demande EN_ATTENTE n'existe déjà pour cette organisation,
 *   calcule le score de confiance, puis notifie les utilisateurs internes.
 *
 * `approve()` : ajoute l'utilisateur à l'organisation via le pivot (relationship_type = requested_role).
 *   `is_default = true` si l'utilisateur n'a encore aucune organisation (première affiliation).
 *   Rejette si l'utilisateur est déjà membre.
 *
 * `reject()` : supprime le justificatif (inutile après refus) et notifie le demandeur.
 *
 * Seules les demandes EN_ATTENTE peuvent être traitées.
 */
class UserOrgRequestService
{
    public function __construct(
        private readonly UserOrgRequestConfidenceScoreService $confidenceScoreService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver,
        private readonly ProofDocumentService $proofDocumentService,
    ) {}

    public function create(User $user, array $data): UserOrgRequest
    {
        $this->ensureUserCanRequestOrganization($user, (int) $data['organization_id']);

        $request = UserOrgRequest::create([
            ...$data,
            'requested_by_user_id' => $user->id,
            'status' => RequestStatus::EN_ATTENTE,
        ])->refresh();

        $request->load('requestedByUser');
        $request->update([
            'confidence_note' => $this->confidenceScoreService->calculate($request),
        ]);

        $this->notificationDispatchService->dispatch(
            users: $this->notificationRecipientResolver->internalUsers(),
            eventKey: 'user_org_request.created',
            category: 'admin_workflow',
            title: 'Nouvelle demande de rattachement organisation',
            message: "La demande #{$request->id} doit être revue par un agent communal."
        );

        return $request->refresh();
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
                'confidence_note' => $confidenceNote ?? $request->confidence_note,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $orgName = $request->organization->name ?? "#{$request->organization_id}";
            $this->notificationDispatchService->dispatch(
                users: collect([$requester]),
                eventKey: 'user_org_request.approved',
                category: 'admin_workflow',
                title: 'Adhésion approuvée',
                message: "Votre demande d’adhésion à l’organisation \"{$orgName}\" a été approuvée."
            );

            return $request->refresh();
        });
    }

    public function reject(UserOrgRequest $request, User $reviewer, string $reason, ?int $confidenceNote = null): UserOrgRequest
    {
        $this->ensurePending($request);

        $requester = $request->requestedByUser;

        $request->update([
            'status' => RequestStatus::REFUSE,
            'confidence_note' => $confidenceNote ?? $request->confidence_note,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Supprimer le justificatif : inutile de le conserver après un refus
        if ($request->proofDocument) {
            $this->proofDocumentService->delete($request->proofDocument);
            $request->update(['proof_document_id' => null]);
        }

        if ($requester) {
            $orgName = $request->organization->name ?? "#{$request->organization_id}";
            $this->notificationDispatchService->dispatch(
                users: collect([$requester]),
                eventKey: 'user_org_request.rejected',
                category: 'admin_workflow',
                title: 'Adhésion refusée',
                message: "Votre demande d’adhésion à l’organisation \"{$orgName}\" a été refusée. Motif : {$reason}"
            );
        }

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
                'organization_id' => 'Une demande est déjà en attente pour cette organisation.',
            ]);
        }
    }

    private function ensureUserIsNotAlreadyMember(User $user, int $organizationId): void
    {
        if ($user->organizations()->whereKey($organizationId)->exists()) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cet utilisateur est déjà rattaché à cette organisation.',
            ]);
        }
    }

    private function ensurePending(UserOrgRequest $request): void
    {
        if (! $request->status->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Cette demande a déjà été traitée.',
            ]);
        }
    }
}
