<?php

namespace App\Services\CouncilDecisions;

use App\Enums\CouncilDecisionStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationConflictType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\Documents\DeliberationDocumentService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Reservations\ReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouncilDecisionService
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly DeliberationDocumentService $deliberationDocumentService,
        private readonly DomainEventLogger $eventLogger,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver
    ) {}

    public function create(array $data, User $actor): CouncilDecision
    {
        $reservation = Reservation::query()
            ->with(['organization', 'councilDecision'])
            ->findOrFail($data['reservation_id']);

        $this->ensureCouncilDecisionRequired($reservation);

        $decision = DB::transaction(function () use ($reservation, $data, $actor): CouncilDecision {
            $decision = CouncilDecision::create([
                'reservation_id' => $reservation->id,
                'decision_status' => $data['decision_status'],
                'decision_date' => $data['decision_date'] ?? null,
                'recorded_by_user_id' => $actor->id,
                'decision_reference' => $data['decision_reference'] ?? null,
                'validated_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->applyDecisionConsequences($decision, $reservation, $actor, $data['notes'] ?? null);
            $this->eventLogger->log(
                aggregateType: 'council_decision',
                aggregateId: $decision->id,
                eventType: 'council_decision.created',
                actor: $actor,
                newValues: $decision->only(['decision_status', 'decision_date', 'decision_reference', 'reservation_id'])
            );

            return $decision->refresh()->load(['reservation.organization', 'recordedByUser']);
        });

        $this->notifyRequesterWhenDeliberationIsGenerated(
            $decision,
            $this->generateDeliberationIfFinal($decision)
        );

        return $decision;
    }

    public function update(CouncilDecision $councilDecision, array $data, User $actor): CouncilDecision
    {
        $councilDecision->loadMissing(['reservation.organization', 'recordedByUser']);

        $this->ensureCouncilDecisionRequired($councilDecision->reservation);

        $councilDecision = DB::transaction(function () use ($councilDecision, $data, $actor): CouncilDecision {
            $councilDecision->update([
                // trace before/after in a single audit event.
                'decision_status' => $data['decision_status'],
                'decision_date' => $data['decision_date'] ?? null,
                'recorded_by_user_id' => $actor->id,
                'decision_reference' => $data['decision_reference'] ?? null,
                'validated_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->applyDecisionConsequences($councilDecision, $councilDecision->reservation, $actor, $data['notes'] ?? null);
            $this->eventLogger->log(
                aggregateType: 'council_decision',
                aggregateId: $councilDecision->id,
                eventType: 'council_decision.updated',
                actor: $actor,
                oldValues: $councilDecision->getOriginal(),
                newValues: $councilDecision->only(['decision_status', 'decision_date', 'decision_reference', 'notes'])
            );

            return $councilDecision->refresh()->load(['reservation.organization', 'recordedByUser']);
        });

        $this->notifyRequesterWhenDeliberationIsGenerated(
            $councilDecision,
            $this->generateDeliberationIfFinal($councilDecision)
        );

        return $councilDecision;
    }

    private function applyDecisionConsequences(
        CouncilDecision $decision,
        Reservation $reservation,
        User $actor,
        ?string $notes
    ): void {
        if ($decision->decision_status === CouncilDecisionStatus::REFUSE) {
            $this->reservationService->reject($reservation, $actor, $notes ?? 'Decision defavorable du college');

            return;
        }

        if ($decision->decision_status === CouncilDecisionStatus::APPROUVE) {
            $this->resolveCouncilDerogationConflicts($reservation, $actor);
        }
    }

    private function ensureCouncilDecisionRequired(Reservation $reservation): void
    {
        if ($reservation->organization->type?->isCouncilDecisionExempt()) {
            throw ValidationException::withMessages([
                'reservation' => 'Aucune decision du college n est requise pour ce type d organisation.',
            ]);
        }
    }

    private function resolveCouncilDerogationConflicts(Reservation $reservation, User $actor): void
    {
        $reservation->conflicts()
            ->where('is_resolved', false)
            ->whereIn('type', [
                ReservationConflictType::DEPARTURE_BEFORE_ALLOWED_TIME->value,
                ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value,
                ReservationConflictType::DURATION_OVER_ALLOWED_LIMIT->value,
            ])
            ->update([
                'is_resolved' => true,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ]);
    }

    private function generateDeliberationIfFinal(CouncilDecision $decision): ?Document
    {
        if ($this->deliberationDocumentService->canGenerateFor($decision)) {
            return $this->deliberationDocumentService->generateFor($decision);
        }

        return null;
    }

    private function notifyRequesterWhenDeliberationIsGenerated(CouncilDecision $decision, ?Document $document): void
    {
        if ($document === null || ! $document->exists) {
            return;
        }

        $decision->loadMissing('reservation.requestedByUser');
        $reservation = $decision->reservation;
        $requester = $this->notificationRecipientResolver->reservationRequester($reservation);

        if ($requester === null) {
            return;
        }

        $statusLabel = $decision->decision_status?->label() ?? (string) $decision->decision_status;

        $this->notificationDispatchService->dispatch(
            users: collect([$requester]),
            eventKey: 'document.deliberation.generated',
            category: 'reservation_updates',
            title: 'Deliberation disponible',
            message: "La decision du college pour votre reservation #{$reservation->id} est {$statusLabel}. La deliberation est disponible.",
            reservation: $reservation,
            document: $document,
            priority: 'normal'
        );
    }
}
