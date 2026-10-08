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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère la création et la mise à jour des décisions du collège communal.
 *
 * Conséquences automatiques selon le statut de la décision :
 *   - REFUSE  → rejette la réservation via ReservationService::reject() (avec le motif).
 *   - APPROUVE → résout les conflits de dérogation (horaire / durée) via
 *                resolveCouncilDerogationConflicts() ; ne touche pas les autres conflits.
 *
 * Toutes les organisations peuvent avoir une décision du collège. Elle est exigée avant approbation pour les types
 * non exemptés (ReservationService::ensureCouncilDecisionAllowsApproval) et facultative pour les autres (écoles,
 * personnel communal) : utile pour une dérogation horaire, avec les mêmes effets (refus → réservation refusée).
 *
 * Après create() ou update(), génère la délibération PDF si le statut est final
 * (APPROUVE ou REFUSE) via DeliberationDocumentService, puis notifie le demandeur.
 */
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


        if ($reservation->hasTripDatePassed()) {
            throw ValidationException::withMessages([
                'reservation' => 'Le trajet est déjà passé : aucune décision du collège ne peut plus être encodée.',
            ]);
        }

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


        // Trajet passé : la décision est figée, seules les notes peuvent encore être corrigées
        if ($councilDecision->reservation->hasTripDatePassed()) {
            return $this->updateNotesOnly($councilDecision, $data, $actor);
        }

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
            $this->reservationService->reject($reservation, $actor, $notes ?? 'Décision défavorable du collège');

            return;
        }

        if ($decision->decision_status === CouncilDecisionStatus::APPROUVE) {
            $this->resolveCouncilDerogationConflicts($reservation, $actor);
        }
    }

    /**
     * Décision d'un trajet passé : statut, date et référence sont figés (422 si on tente de les changer) ;
     * seules les notes sont mises à jour, sans réappliquer les conséquences ni régénérer la délibération.
     */
    private function updateNotesOnly(CouncilDecision $councilDecision, array $data, User $actor): CouncilDecision
    {
        $statusChanged = ($data['decision_status'] ?? null) !== null
            && $data['decision_status'] !== $councilDecision->decision_status?->value;
        // Date comparée au jour près, quel que soit le format reçu (« 2026-06-10 » ou « 2026-06-10T00:00:00Z »)
        $receivedDate = filled($data['decision_date'] ?? null)
            ? Carbon::parse($data['decision_date'])->toDateString()
            : null;
        $dateChanged = array_key_exists('decision_date', $data)
            && $receivedDate !== $councilDecision->decision_date?->toDateString();
        $referenceChanged = array_key_exists('decision_reference', $data)
            && ($data['decision_reference'] ?? null) !== $councilDecision->decision_reference;

        if ($statusChanged || $dateChanged || $referenceChanged) {
            throw ValidationException::withMessages([
                'reservation' => 'Le trajet est passé : seules les notes de la décision peuvent encore être modifiées.',
            ]);
        }

        $oldNotes = $councilDecision->notes;
        $councilDecision->update(['notes' => $data['notes'] ?? null]);

        // Rien n'a changé : pas d'événement d'audit
        if (! $councilDecision->wasChanged('notes')) {
            return $councilDecision->refresh()->load(['reservation.organization', 'recordedByUser']);
        }

        $this->eventLogger->log(
            aggregateType: 'council_decision',
            aggregateId: $councilDecision->id,
            eventType: 'council_decision.notes_updated',
            actor: $actor,
            oldValues: ['notes' => $oldNotes],
            newValues: ['notes' => $councilDecision->notes]
        );

        return $councilDecision->refresh()->load(['reservation.organization', 'recordedByUser']);
    }

    /**
     * Décision favorable : lève les dérogations horaires, puis fait avancer la réservation s'il ne reste aucun conflit
     * ouvert (même suite qu'une résolution manuelle, ReservationService::checkAndAutoTransition).
     */
    private function resolveCouncilDerogationConflicts(Reservation $reservation, User $actor): void
    {
        $resolved = $reservation->conflicts()
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

        if ($resolved > 0) {
            $this->reservationService->checkAndAutoTransition($reservation, $actor);
        }
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
            title: 'Délibération disponible',
            message: "La décision du collège pour votre réservation #{$reservation->id} est {$statusLabel}. La délibération est disponible.",
            reservation: $reservation,
            document: $document,
            priority: 'normal'
        );
    }
}
