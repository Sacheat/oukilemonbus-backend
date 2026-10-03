<?php

namespace App\Services\OrgRegistrationRequests;

use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use App\Models\UserOrganization;
use App\Services\Documents\ProofDocumentService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère le cycle de vie des demandes de création d'organisation.
 *
 * `create()` : calcule le score de confiance immédiatement après la création de la demande,
 *   puis notifie les utilisateurs internes (admin/agent).
 *
 * `approve()` : crée l'organisation, ajoute le demandeur en tant que responsable (is_default = true
 *   seulement s'il n'a encore aucune organisation par défaut : une seule par utilisateur),
 *   passe la demande en APPROUVE, supprime le justificatif si conservé pour refus futur,
 *   et notifie le demandeur.
 *
 * `reject()` : supprime le justificatif (inutile après refus) via ProofDocumentService
 *   pour libérer l'espace disque et notifie le demandeur.
 *
 * Seules les demandes EN_ATTENTE peuvent être approuvées ou refusées.
 *
 * Note de confiance (`confidence_note`, qui sert aussi au tri de la liste) : enregistrée à la création, rafraîchie par
 * `refreshConfidenceNote()` quand le justificatif est ajouté ou supprimé (la SPA l'envoie après la création), puis figée
 * à la décision : approve() et reject() enregistrent le score du moment, avant la suppression du justificatif d'un refus.
 */
class OrgRegistrationRequestService
{
    public function __construct(
        private readonly OrgRegistrationConfidenceScoreService $confidenceScoreService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver,
        private readonly ProofDocumentService $proofDocumentService,
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

        $this->notificationDispatchService->dispatch(
            users: $this->notificationRecipientResolver->internalUsers(),
            eventKey: 'org_registration_request.created',
            category: 'admin_workflow',
            title: 'Nouvelle demande de création d’organisation',
            message: "La demande #{$request->id} doit être revue par un agent communal."
        );

        return $request->refresh();
    }

    /**
     * Recalcule et enregistre la note d'une demande EN_ATTENTE ; une demande traitée garde la note de sa décision.
     *
     * @return bool true si la note enregistrée a changé
     */
    public function refreshConfidenceNote(OrgRegistrationRequest $request): bool
    {
        if (! $request->status->isPending()) {
            return false;
        }

        $score = $this->confidenceScoreService->calculate($request);

        if ($request->confidence_note === $score) {
            return false;
        }

        $request->update(['confidence_note' => $score]);

        return true;
    }

    public function approve(OrgRegistrationRequest $request, User $reviewer): Organization
    {
        $this->ensurePending($request);

        // Avant la création de l'organisation : elle porte le même email officiel et ferait perdre des points.
        $confidenceNote = $this->confidenceScoreService->calculate($request);

        return DB::transaction(function () use ($request, $reviewer, $confidenceNote): Organization {
            $organizationType = $request->organization_type;

            $organization = Organization::create([
                'name' => $request->organization_name,
                'type' => $organizationType,
                'official_email' => $request->official_email,
                'phone_contact' => $request->phone,
                'name_contact' => $request->contact_person_name,
                'location_id' => $request->location_id,
                'is_priority' => $organizationType->isPriorityByDefault(),
                'proof_document_id' => $request->proof_document_id,
            ])->refresh();

            $hasDefault = UserOrganization::query()
                ->where('user_id', $request->request_by_user_id)
                ->where('is_default', true)
                ->exists();

            $organization->users()->syncWithoutDetaching([
                $request->request_by_user_id => [
                    'relationship_type' => 'responsable',
                    'is_default' => ! $hasDefault,
                ],
            ]);

            $request->update([
                'status' => RequestStatus::APPROUVE,
                'confidence_note' => $confidenceNote,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $requester = $request->requestedByUser;
            if ($requester) {
                $this->notificationDispatchService->dispatch(
                    users: collect([$requester]),
                    eventKey: 'org_registration_request.approved',
                    category: 'admin_workflow',
                    title: 'Demande d’organisation approuvée',
                    message: "Votre demande #{$request->id} a été approuvée. L’organisation \"{$organization->name}\" a été créée."
                );
            }

            return $organization;
        });
    }

    public function reject(OrgRegistrationRequest $request, User $reviewer, string $reason): OrgRegistrationRequest
    {
        $this->ensurePending($request);

        $requester = $request->requestedByUser;

        $request->update([
            'status' => RequestStatus::REFUSE,
            'confidence_note' => $this->confidenceScoreService->calculate($request),
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
            $this->notificationDispatchService->dispatch(
                users: collect([$requester]),
                eventKey: 'org_registration_request.rejected',
                category: 'admin_workflow',
                title: 'Demande d’organisation refusée',
                message: "Votre demande #{$request->id} a été refusée. Motif : {$reason}"
            );
        }

        return $request->refresh();
    }

    private function ensurePending(OrgRegistrationRequest $request): void
    {
        if (! $request->status->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Cette demande a déjà été traitée.',
            ]);
        }
    }
}
