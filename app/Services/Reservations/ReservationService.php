<?php

namespace App\Services\Reservations;

use App\Enums\ApprovalType;
use App\Enums\CouncilDecisionStatus;
use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\ReservationStatusHistory;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\Documents\RouteSheetDocumentService;
use App\Services\Logging\DomainLogger;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Orchestre le cycle de vie complet d'une réservation.
 *
 * Responsabilités principales :
 * - Création : PreviewService valide le créneau, puis transition initiale vers
 *   EN_ATTENTE, APPROUVE ou EN_CONFLIT selon le type d'organisation et les conflits.
 * - Transitions : approve(), reject(), cancel(), confirm() — chacune émet un DomainEvent
 *   et notifie le demandeur / le chauffeur selon la situation.
 * - Résolution de conflits : ensureScheduleOverlapGone() vérifie que le chevauchement a
 *   réellement disparu avant de marquer le conflit résolu ; autoTransitionFromConflict()
 *   retransitionne ensuite vers CONFIRME ou APPROUVE selon l'historique.
 * - Impact d'indisponibilité : applyUnavailabilityImpact() désassigne le chauffeur ou
 *   le véhicule indisponible et passe les réservations concernées EN_CONFLIT.
 * - Annulation last-minute : catégorie séparée (priorité high, last_minute_cancellation).
 * - Délais d'annulation : configurable — heures pour le demandeur, jours pour la commune.
 */
class ReservationService
{
    private DomainLogger $logger;

    public function __construct(
        private readonly ReservationPreviewService $previewService,
        private readonly ReservationAssignmentService $assignmentService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver,
        private readonly DomainEventLogger $eventLogger,
        private readonly RouteSheetDocumentService $routeSheetDocumentService,
        private readonly ReservationOccupationService $occupationService,
        ?DomainLogger $logger = null
    ) {
        $this->logger = $logger ?? app(DomainLogger::class);
    }

    public function create(array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($data, $actor): Reservation {
            $organization = Organization::findOrFail($data['organization_id']);
            $requestedByUserId = $actor->hasAnyRole(['admin', 'agent_communal'])
                ? ($data['requested_by_user_id'] ?? $actor->id)
                : $actor->id;

            if (! Gate::forUser($actor)->allows('createForOrganization', [Reservation::class, $organization])) {
                throw new AuthorizationException('Action non autorisée pour cette organisation.');
            }

            $preview = $this->previewService->preview($data);

            if (! ($preview['is_allowed'] ?? true)) {
                $hardBlock = collect($preview['conflicts'])->first(fn (array $c): bool => $c['is_hard_block'] ?? false);
                throw ValidationException::withMessages([
                    'schedule' => [$hardBlock['message'] ?? 'La réservation ne peut pas être créée : le créneau est physiquement bloquant et ne peut pas être résolu par l\'agent.'],
                ]);
            }

            $status = ReservationStatus::from($preview['status']);

            $reservation = Reservation::create([
                ...Arr::only($data, [
                    'organization_id',
                    'pickup_location_id',
                    'destination_location_id',
                    'trip_date',
                    'departure_time',
                    'return_time',
                    'nb_children',
                    'nb_adults',
                    'trip_purpose',
                    'activity_type',
                    'recurrence_id',
                ]),
                'requested_by_user_id' => $requestedByUserId,
                'distance_km' => $preview['distance_km'],
                'estimated_duration_min' => $preview['estimated_duration_min'],
                'status' => $status,
                'approval_type' => $preview['approval_type'],
                'validated_by_user_id' => $status === ReservationStatus::APPROUVE ? $actor->id : null,
                'validated_at' => $status === ReservationStatus::APPROUVE ? now() : null,
            ]);

            $this->recordStatus($reservation, null, $status, $actor, 'Création de la demande');
            $this->syncConflicts($reservation, $preview['conflicts']);
            $this->logger->reservationCreated($reservation, $actor, $preview);
            $this->eventLogger->log(
                aggregateType: 'reservation',
                aggregateId: $reservation->id,
                eventType: 'reservation.created',
                actor: $actor,
                newValues: $reservation->only(['status', 'organization_id', 'trip_date', 'departure_time', 'return_time', 'driver_id', 'vehicle_id']),
                context: ['preview' => Arr::only($preview, ['status', 'approval_type', 'distance_km', 'estimated_duration_min'])]
            );

            if ($status === ReservationStatus::APPROUVE) {
                $reservation = $this->assignmentService->autoAssign($reservation, $actor);
                if ($this->assignmentService->hasRequiredAssignments($reservation) && ! $reservation->conflicts()->where('is_resolved', false)->exists()) {
                    $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation automatique');
                }
            }

            if ($status === ReservationStatus::EN_CONFLIT) {
                $this->notifyInternalUsers(
                    'reservation.conflict_detected',
                    'admin_workflow',
                    'Conflit de réservation détecté',
                    "La réservation #{$reservation->id} a été créée avec un conflit.",
                    $reservation
                );
            }

            return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
        });
    }

    public function update(Reservation $reservation, array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $actor): Reservation {
            // Valeurs actuelles au format des données entrantes (Y-m-d, H:i) : les attributs castés
            // (dates Carbon, enum) faisaient échouer le preview lors d'une modification partielle.
            $current = [
                ...$reservation->only([
                    'organization_id',
                    'pickup_location_id',
                    'destination_location_id',
                    'nb_children',
                    'nb_adults',
                    'trip_purpose',
                ]),
                'trip_date' => $reservation->trip_date?->toDateString(),
                'departure_time' => $reservation->departure_time?->format('H:i'),
                'return_time' => $reservation->return_time?->format('H:i'),
                'activity_type' => $reservation->activity_type?->value,
            ];

            $payload = array_merge($current, $data, ['ignore_reservation_id' => $reservation->id]);

            $preview = $this->previewService->preview($payload);
            $currentStatus = $reservation->status;
            $newStatus = count($preview['conflicts']) > 0 ? ReservationStatus::EN_CONFLIT : $currentStatus;

            if ($newStatus !== $currentStatus) {
                $this->assertCanTransition($reservation, $newStatus);
            }

            $reservation->update([
                ...Arr::only($data, [
                    'pickup_location_id',
                    'destination_location_id',
                    'trip_date',
                    'departure_time',
                    'return_time',
                    'nb_children',
                    'nb_adults',
                    'trip_purpose',
                    'activity_type',
                ]),
                'distance_km' => $preview['distance_km'],
                'estimated_duration_min' => $preview['estimated_duration_min'],
                'status' => $newStatus,
            ]);

            if ($newStatus !== $currentStatus) {
                $this->recordStatus($reservation, $currentStatus, $newStatus, $actor, 'Modification de la demande');
            }

            $reservation->conflicts()->delete();
            $this->syncConflicts($reservation, $preview['conflicts']);

            // Réservation en conflit corrigée par la modification (plus aucun conflit) : même logique
            // que la résolution manuelle (en_attente, ou approbation / affectation auto selon le cas).
            $this->checkAndAutoTransition($reservation, $actor);

            $this->logger->reservationUpdated($reservation, $actor, $preview);
            $this->eventLogger->log(
                aggregateType: 'reservation',
                aggregateId: $reservation->id,
                eventType: 'reservation.updated',
                actor: $actor,
                oldValues: Arr::only($reservation->getOriginal(), ['trip_date', 'departure_time', 'return_time', 'nb_children', 'nb_adults', 'status']),
                newValues: $reservation->only(['trip_date', 'departure_time', 'return_time', 'nb_children', 'nb_adults', 'status']),
                context: ['conflicts_count' => count($preview['conflicts'])]
            );

            return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
        });
    }

    public function confirmPassengers(Reservation $reservation, array $data, User $actor): Reservation
    {
        $nbAdults   = (int) ($data['nb_adults']   ?? $reservation->nb_adults);
        $nbChildren = (int) ($data['nb_children'] ?? $reservation->nb_children);

        if ($reservation->vehicle_id !== null) {
            $reservation->loadMissing('vehicle');
            $capacity = $reservation->vehicle?->capacity;

            // La capacité inclut le chauffeur, qui occupe 1 place
            if ($capacity !== null && ($nbAdults + $nbChildren) > ($capacity - 1)) {
                $maxPassengers = $capacity - 1;
                throw ValidationException::withMessages([
                    'nb_adults' => "Le véhicule assigné ({$reservation->vehicle->licence_plate}) a une capacité de {$capacity} places chauffeur compris, soit {$maxPassengers} passager(s) maximum. Le total demandé ({$nbAdults} adulte(s) + {$nbChildren} enfant(s) = " . ($nbAdults + $nbChildren) . ") dépasse cette limite.",
                ]);
            }
        }

        $oldValues = $reservation->only(['nb_children', 'nb_adults', 'trip_purpose', 'passengers_confirmed_at']);

        $reservation->update([
            ...Arr::only($data, ['nb_children', 'nb_adults', 'trip_purpose']),
            'passengers_confirmed_at' => now(),
        ]);

        $this->eventLogger->log(
            aggregateType: 'reservation',
            aggregateId: $reservation->id,
            eventType: 'reservation.passengers_confirmed',
            actor: $actor,
            oldValues: $oldValues,
            newValues: $reservation->only(['nb_children', 'nb_adults', 'trip_purpose', 'passengers_confirmed_at']),
        );

        $this->notifyRequester(
            $reservation,
            'reservation.passengers_confirmed',
            'reservation_updates',
            'Passagers confirmes',
            "Les informations passagers de la réservation #{$reservation->id} ont été confirmées ({$reservation->nb_adults} adulte(s), {$reservation->nb_children} enfant(s))."
        );

        // Si le trajet est aujourd'hui et qu'un véhicule est assigné, régénérer la feuille de route
        if ($reservation->vehicle_id !== null && $reservation->trip_date?->toDateString() === Reservation::localToday()) {
            try {
                $this->routeSheetDocumentService->generateForServiceDateVehicle(
                    Reservation::localToday(),
                    $reservation->vehicle_id
                );
            } catch (\Throwable) {
                // Non bloquant : la feuille sera regénérée manuellement si besoin
            }
        }

        return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
    }

    public function approve(Reservation $reservation, User $actor, ?string $reason = null): Reservation
    {
        if ($reservation->hasTripDatePassed()) {
            throw ValidationException::withMessages([
                'reservation' => 'Le trajet est déjà passé : la réservation ne peut plus être approuvée.',
            ]);
        }

        $this->ensureCouncilDecisionAllowsApproval($reservation);

        return DB::transaction(function () use ($reservation, $actor, $reason): Reservation {
            $reservation = $this->transition($reservation, ReservationStatus::APPROUVE, $actor, $reason ?? 'Approbation');
            $reservation = $this->assignmentService->autoAssign($reservation, $actor);

            if ($this->assignmentService->hasRequiredAssignments($reservation) && ! $reservation->conflicts()->where('is_resolved', false)->exists()) {
                $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation après approbation');
            }

            $this->notifyRequester(
                $reservation,
                'reservation.approved',
                'reservation_updates',
                'Réservation approuvée',
                "Votre réservation #{$reservation->id} a été approuvée.",
                document: $this->latestDeliberationDocumentForReservation($reservation)
            );

            return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
        });
    }

    public function reject(Reservation $reservation, User $actor, string $reason): Reservation
    {
        $this->assertCanTransition($reservation, ReservationStatus::REFUSE);

        return DB::transaction(function () use ($reservation, $actor, $reason): Reservation {
            $reservation->update([
                'status' => ReservationStatus::REFUSE,
                'rejection_reason' => $reason,
            ]);

            $this->recordStatus($reservation, $reservation->getOriginal('status'), ReservationStatus::REFUSE, $actor, $reason);
            $this->eventLogger->log(
                aggregateType: 'reservation',
                aggregateId: $reservation->id,
                eventType: 'reservation.rejected',
                actor: $actor,
                oldValues: ['status' => $reservation->getOriginal('status')],
                newValues: ['status' => ReservationStatus::REFUSE->value, 'rejection_reason' => $reason]
            );

            $this->notifyRequester(
                $reservation,
                'reservation.rejected',
                'reservation_updates',
                'Réservation refusée',
                "Votre réservation #{$reservation->id} a été refusée. Motif: {$reason}",
                document: $this->latestDeliberationDocumentForReservation($reservation)
            );

            $this->autoResolveRelatedConflicts($reservation, $actor);

            return $reservation->refresh();
        });
    }

    public function cancel(Reservation $reservation, User $actor, string $reason): Reservation
    {
        $this->assertCanTransition($reservation, ReservationStatus::ANNULE);
        $this->ensureCancellationDeadlineNotPassed($reservation, $actor);

        return DB::transaction(function () use ($reservation, $actor, $reason): Reservation {
            $reservation->update([
                'status' => ReservationStatus::ANNULE,
                'cancelled_by_user_id' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->recordStatus($reservation, $reservation->getOriginal('status'), ReservationStatus::ANNULE, $actor, $reason);
            $this->eventLogger->log(
                aggregateType: 'reservation',
                aggregateId: $reservation->id,
                eventType: 'reservation.cancelled',
                actor: $actor,
                oldValues: ['status' => $reservation->getOriginal('status')],
                newValues: ['status' => ReservationStatus::ANNULE->value, 'cancellation_reason' => $reason]
            );

            $isLastMinute = $this->isLastMinuteCancellation($reservation);
            $category = $isLastMinute ? 'last_minute_cancellation' : 'reservation_updates';
            $priority = $isLastMinute ? 'high' : 'normal';

            $this->notifyRequester(
                $reservation,
                'reservation.cancelled',
                $category,
                'Réservation annulée',
                "Votre réservation #{$reservation->id} a été annulée. Motif: {$reason}",
                $priority,
                $isLastMinute
            );

            $this->notifyDriver(
                $reservation,
                'reservation.cancelled',
                $category,
                'Annulation de réservation',
                "La réservation #{$reservation->id} a été annulée. Motif: {$reason}",
                $priority,
                $isLastMinute
            );

            $this->autoResolveRelatedConflicts($reservation, $actor);

            return $reservation->refresh();
        });
    }

    public function confirm(Reservation $reservation, User $actor): Reservation
    {
        if (! $this->assignmentService->hasRequiredAssignments($reservation)) {
            throw ValidationException::withMessages([
                'reservation' => 'La réservation doit avoir un chauffeur et un véhicule assignés.',
            ]);
        }

        if ($reservation->conflicts()->where('is_resolved', false)->exists()) {
            throw ValidationException::withMessages([
                'reservation' => 'Tous les conflits doivent être résolus avant confirmation.',
            ]);
        }

        $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation');

        $this->notifyRequester(
            $reservation,
            'reservation.confirmed',
            'reservation_updates',
            'Réservation confirmée',
            "Votre réservation #{$reservation->id} est confirmée."
        );

        $this->notifyDriver(
            $reservation,
            'reservation.confirmed',
            'driver_planning',
            'Mission confirmée',
            "La mission liée à la réservation #{$reservation->id} est confirmée."
        );

        return $reservation;
    }

    public function retryAutoAssign(Reservation $reservation, User $actor): Reservation
    {
        $reservation = $this->assignmentService->autoAssign($reservation, $actor);
        $this->checkAndAutoTransition($reservation, $actor);

        return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
    }

    public function assign(Reservation $reservation, array $data, User $actor): Reservation
    {
        $reservation = $this->assignmentService->assign($reservation, $data, $actor);
        $this->checkAndAutoTransition($reservation, $actor);

        return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
    }

    public function resolveConflict(ReservationConflict $conflict, User $actor): ReservationConflict
    {
        if ($conflict->type === ReservationConflictType::SCHEDULE_OVERLAP) {
            $this->ensureScheduleOverlapGone($conflict);
        }

        $conflict->update([
            'is_resolved' => true,
            'resolved_by_user_id' => $actor->id,
            'resolved_at' => now(),
        ]);

        $this->checkAndAutoTransition(Reservation::findOrFail($conflict->reservation_id), $actor);

        return $conflict->refresh();
    }

    /**
     * Déclenche autoTransitionFromConflict si la réservation est EN_CONFLIT
     * et n'a plus aucun conflit non-résolu.
     */
    public function checkAndAutoTransition(Reservation $reservation, User $actor): void
    {
        $reservation->refresh();
        if ($reservation->status === ReservationStatus::EN_CONFLIT
            && ! $reservation->conflicts()->where('is_resolved', false)->exists()
        ) {
            $this->autoTransitionFromConflict($reservation, $actor);
        }
    }

    /**
     * Vérifie que le chevauchement calendaire a réellement disparu avant de permettre
     * la résolution manuelle du conflit. Bloque si la réservation liée est encore active
     * et que les créneaux se chevauchent toujours.
     */
    private function ensureScheduleOverlapGone(ReservationConflict $conflict): void
    {
        if ($conflict->related_reservation_id === null) {
            return; // La réservation source n'existe plus, aucun chevauchement possible.
        }

        $related = Reservation::find($conflict->related_reservation_id);

        if ($related === null || $related->status->isTerminal()) {
            return; // La réservation liée est terminale, plus aucun conflit réel.
        }

        $reservation = Reservation::findOrFail($conflict->reservation_id);

        $remaining = $this->previewService->detectConflicts([
            'organization_id'       => $reservation->organization_id,
            'trip_date'             => $reservation->trip_date->toDateString(),
            'departure_time'        => $reservation->departure_time->format('H:i:s'),
            'return_time'           => $reservation->return_time->format('H:i:s'),
            'ignore_reservation_id' => $reservation->id,
        ], $reservation->estimated_duration_min);

        $stillOverlaps = collect($remaining)
            ->contains('type', ReservationConflictType::SCHEDULE_OVERLAP->value);

        if ($stillOverlaps) {
            throw ValidationException::withMessages([
                'conflict' => 'Le chevauchement calendaire est toujours actif. La réservation liée doit être annulée ou ses horaires modifiés avant de résoudre ce conflit.',
            ]);
        }
    }

    /**
     * Résout automatiquement les conflits SCHEDULE_OVERLAP pointant vers une réservation
     * qui vient de devenir terminale (annulée ou refusée). Si la réservation bloquée
     * n'a plus de conflit non résolu, elle est retransitionnée vers son statut logique.
     */
    private function autoResolveRelatedConflicts(Reservation $terminal, User $actor): void
    {
        $relatedConflicts = ReservationConflict::query()
            ->where('related_reservation_id', $terminal->id)
            ->where('type', ReservationConflictType::SCHEDULE_OVERLAP->value)
            ->where('is_resolved', false)
            ->with('reservation')
            ->get();

        foreach ($relatedConflicts as $conflict) {
            /** @var Reservation|null $affected */
            $affected = $conflict->reservation;

            if ($affected === null || $affected->status->isTerminal()) {
                continue;
            }

            $conflict->update([
                'is_resolved'          => true,
                'resolved_by_user_id'  => $actor->id,
                'resolved_at'          => now(),
            ]);

            $affected->refresh();

            if (! $affected->conflicts()->where('is_resolved', false)->exists()) {
                $this->autoTransitionFromConflict($affected, $actor);
            }
        }
    }

    /**
     * Retransitionne une réservation hors de EN_CONFLIT en respectant le statut
     * qu'elle avait avant le conflit (CONFIRME → CONFIRME si les assignations sont
     * toujours présentes, sinon APPROUVE → tentative de confirmation).
     */
    private function autoTransitionFromConflict(Reservation $reservation, User $actor): void
    {
        if ($reservation->status !== ReservationStatus::EN_CONFLIT) {
            return;
        }

        $preConflictStatus = $this->preConflictStatus($reservation);

        // Cas : était CONFIRME et driver+vehicle toujours assignés → retour CONFIRME direct
        if ($preConflictStatus === ReservationStatus::CONFIRME
            && $this->assignmentService->hasRequiredAssignments($reservation)
        ) {
            if (! $reservation->status->canTransitionTo(ReservationStatus::CONFIRME)) {
                return;
            }
            $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Retour automatique à CONFIRME après résolution de tous les conflits.');
            $this->notifyRequester(
                $reservation,
                'reservation.confirmed',
                'reservation_updates',
                'Réservation de nouveau confirmée',
                "Votre réservation #{$reservation->id} est de nouveau confirmée après résolution des conflits."
            );
            $this->notifyDriver(
                $reservation,
                'reservation.confirmed',
                'driver_planning',
                'Mission de nouveau confirmée',
                "La mission liée à la réservation #{$reservation->id} est de nouveau confirmée."
            );
            return;
        }

        // Cas : était CONFIRME ou APPROUVE mais assignations incomplètes → APPROUVE + tentative
        if (in_array($preConflictStatus, [ReservationStatus::CONFIRME, ReservationStatus::APPROUVE], true)) {
            if (! $reservation->status->canTransitionTo(ReservationStatus::APPROUVE)) {
                return;
            }
            $reservation = $this->transition($reservation, ReservationStatus::APPROUVE, $actor, 'Retour automatique à APPROUVE après résolution des conflits.');
            $reservation = $this->assignmentService->autoAssign($reservation, $actor);

            if ($this->assignmentService->hasRequiredAssignments($reservation)
                && ! $reservation->conflicts()->where('is_resolved', false)->exists()
            ) {
                $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation automatique après résolution de conflit.');
            }
            $this->notifyRequester(
                $reservation,
                'reservation.approved',
                'reservation_updates',
                'Réservation disponible',
                "Votre réservation #{$reservation->id} est de nouveau disponible suite à la résolution des conflits."
            );
            return;
        }

        // Cas par défaut : EN_ATTENTE, puis auto-approve si l'org le permet (ex. école)
        if (! $reservation->status->canTransitionTo(ReservationStatus::EN_ATTENTE)) {
            return;
        }
        $reservation = $this->transition($reservation, ReservationStatus::EN_ATTENTE, $actor, 'Retour automatique après résolution des conflits.');

        $reservation->loadMissing('organization');

        if ($reservation->organization->type?->isAutoApproveType()) {
            if (! $reservation->status->canTransitionTo(ReservationStatus::APPROUVE)) {
                return;
            }
            $reservation = $this->transition($reservation, ReservationStatus::APPROUVE, $actor, 'Approbation automatique après résolution des conflits.');
            $reservation = $this->assignmentService->autoAssign($reservation, $actor);

            if ($this->assignmentService->hasRequiredAssignments($reservation)
                && ! $reservation->conflicts()->where('is_resolved', false)->exists()
            ) {
                $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation automatique après résolution des conflits.');
            }

            $this->notifyRequester(
                $reservation,
                'reservation.approved',
                'reservation_updates',
                'Réservation disponible',
                "Votre réservation #{$reservation->id} est de nouveau disponible suite à la résolution des conflits."
            );

            return;
        }

        $this->notifyRequester(
            $reservation,
            'reservation.conflict_resolved',
            'reservation_updates',
            'Conflit résolu',
            "Le conflit sur votre réservation #{$reservation->id} a été résolu. Elle est en attente de validation."
        );
    }

    /**
     * Retourne le statut de la réservation juste avant sa dernière transition vers EN_CONFLIT.
     * Utilisé pour savoir vers quel statut revenir après résolution.
     */
    private function preConflictStatus(Reservation $reservation): ?ReservationStatus
    {
        $entry = ReservationStatusHistory::query()
            ->where('reservation_id', $reservation->id)
            ->where('new_status', ReservationStatus::EN_CONFLIT->value)
            ->latest('id')
            ->first();

        return $entry?->old_status instanceof ReservationStatus ? $entry->old_status : null;
    }

    private function ensureCouncilDecisionApprovesDerogation(ReservationConflict $conflict): void
    {
        $reservation = Reservation::with('councilDecision')->findOrFail($conflict->reservation_id);

        if ($reservation->councilDecision?->decision_status !== CouncilDecisionStatus::APPROUVE) {
            throw ValidationException::withMessages([
                'conflict' => 'Ce conflit nécessite une décision favorable du collège communal avant d’être résolu. Ajoutez ou mettez à jour la décision du collège pour cette réservation.',
            ]);
        }
    }

    private function ensureCouncilDecisionAllowsApproval(Reservation $reservation): void
    {
        if ($reservation->organization->type?->isCouncilDecisionExempt()) {
            return;
        }

        if ($reservation->councilDecision?->decision_status !== CouncilDecisionStatus::APPROUVE) {
            throw ValidationException::withMessages([
                'reservation' => 'Une décision favorable du collège est obligatoire avant approbation.',
            ]);
        }
    }

    /**
     * Détecte les réservations APPROUVE/CONFIRME déjà assignées au chauffeur ou véhicule
     * de l'indisponibilité et dont la fenêtre d'occupation chevauche la période d'indispo.
     * Pour chacune : crée un conflit NO_DRIVER/VEHICLE_AVAILABLE + passe en EN_CONFLIT.
     * Retourne le nombre de réservations impactées.
     */
    public function applyUnavailabilityImpact(\App\Models\Unavailability $unavailability, User $actor): int
    {
        $driverId  = $unavailability->driver_id;
        $vehicleId = $unavailability->vehicle_id;
        $start     = CarbonImmutable::parse($unavailability->start_datetime);
        $end       = CarbonImmutable::parse($unavailability->end_datetime);

        $conflictType = $driverId !== null
            ? ReservationConflictType::NO_DRIVER_AVAILABLE
            : ReservationConflictType::NO_VEHICLE_AVAILABLE;

        $query = Reservation::query()
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            ->whereDate('trip_date', '>=', $start->toDateString())
            ->whereDate('trip_date', '<=', $end->toDateString());

        if ($driverId !== null) {
            $query->where('driver_id', $driverId);
        } else {
            $query->where('vehicle_id', $vehicleId);
        }

        $affected = 0;

        foreach ($query->get() as $reservation) {
            $window = $this->occupationService->windowForReservation($reservation);

            if (! ($window['start']->lt($end) && $window['end']->gt($start))) {
                continue;
            }

            $alreadyFlagged = $reservation->conflicts()
                ->where('type', $conflictType->value)
                ->where('is_resolved', false)
                ->exists();

            if ($alreadyFlagged) {
                continue;
            }

            $subjectLabel = $this->subjectLabel($unavailability);

            $message = "{$subjectLabel} indisponible du {$start->format('d/m/Y H\hi')} au {$end->format('d/m/Y H\hi')}.";
            if ($unavailability->reason) {
                $message .= " Motif : {$unavailability->reason}.";
            }

            DB::transaction(function () use ($reservation, $conflictType, $message, $actor, $unavailability, $driverId, $vehicleId): void {
                ReservationConflict::create([
                    'reservation_id' => $reservation->id,
                    'type' => $conflictType,
                    'message' => $message,
                    'related_reservation_id' => null,
                ]);

                // Désassigner le chauffeur ou le véhicule concerné pour que la feuille
                // de route ne référence pas quelqu'un d'indisponible
                $unassignField = $driverId !== null ? 'driver_id' : 'vehicle_id';
                $oldAssignedId = $reservation->{$unassignField};
                $reservation->update([$unassignField => null]);

                if ($reservation->status->canTransitionTo(ReservationStatus::EN_CONFLIT)) {
                    $this->transition($reservation, ReservationStatus::EN_CONFLIT, $actor, 'Indisponibilité créée pour le chauffeur ou véhicule assigné.');
                }

                $this->notifyInternalUsers(
                    'reservation.conflict_detected',
                    'admin_workflow',
                    'Conflit : indisponibilité',
                    "La réservation #{$reservation->id} est en conflit suite à une indisponibilité de {$this->subjectLabel($unavailability)}.",
                    $reservation
                );

                $this->eventLogger->log(
                    aggregateType: 'reservation',
                    aggregateId: $reservation->id,
                    eventType: 'reservation.unavailability_conflict',
                    actor: $actor,
                    oldValues: [$unassignField => $oldAssignedId],
                    newValues: [$unassignField => null],
                    context: ['unavailability_id' => $unavailability->id, 'conflict_type' => $conflictType->value]
                );
            });

            $affected++;
        }

        return $affected;
    }

    private function subjectLabel(\App\Models\Unavailability $unavailability): string
    {
        if ($unavailability->driver_id !== null) {
            $u = $unavailability->driver?->user;
            $name = $u ? trim("{$u->first_name} {$u->last_name}") : null;

            return $name ?: "Chauffeur #{$unavailability->driver_id}";
        }

        return $unavailability->vehicle?->licence_plate ?? "Véhicule #{$unavailability->vehicle_id}";
    }

    private function assertCanTransition(Reservation $reservation, ReservationStatus $target): void
    {
        if (! $reservation->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'reservation' => "La transition « {$reservation->status->label()} » → « {$target->label()} » n'est pas autorisée.",
            ]);
        }
    }

    private function transition(Reservation $reservation, ReservationStatus $newStatus, User $actor, string $reason): Reservation
    {
        $this->assertCanTransition($reservation, $newStatus);

        $oldStatus = $reservation->status;
        $reservation->update([
            'status' => $newStatus,
            'validated_by_user_id' => in_array($newStatus, [ReservationStatus::APPROUVE, ReservationStatus::CONFIRME], true)
                ? $actor->id
                : $reservation->validated_by_user_id,
            'validated_at' => in_array($newStatus, [ReservationStatus::APPROUVE, ReservationStatus::CONFIRME], true)
                ? ($reservation->validated_at ?? now())
                : $reservation->validated_at,
        ]);

        $this->recordStatus($reservation, $oldStatus, $newStatus, $actor, $reason);
        $this->eventLogger->log(
            aggregateType: 'reservation',
            aggregateId: $reservation->id,
            eventType: 'reservation.status_transition',
            actor: $actor,
            oldValues: ['status' => $oldStatus->value],
            newValues: ['status' => $newStatus->value],
            context: ['reason' => $reason]
        );

        return $reservation->refresh();
    }

    private function recordStatus(Reservation $reservation, ReservationStatus|string|null $oldStatus, ReservationStatus $newStatus, User $actor, string $reason): void
    {
        ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            'old_status' => $oldStatus instanceof ReservationStatus ? $oldStatus : ($oldStatus ?? $newStatus),
            'new_status' => $newStatus,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
        ]);

        $this->logger->reservationStatusChanged(
            $reservation,
            $actor,
            $oldStatus instanceof ReservationStatus ? $oldStatus->value : (string) ($oldStatus ?? $newStatus->value),
            $newStatus->value,
            $reason
        );
    }

    private function syncConflicts(Reservation $reservation, array $conflicts): void
    {
        foreach ($conflicts as $conflict) {
            ReservationConflict::create([
                'reservation_id' => $reservation->id,
                'type' => $conflict['type'],
                'message' => $conflict['message'],
                'related_reservation_id' => $conflict['related_reservation_id'] ?? null,
            ]);
        }
    }

    private function notifyInternalUsers(
        string $eventKey,
        string $category,
        string $title,
        string $message,
        Reservation $reservation,
        string $priority = 'normal',
        bool $urgent = false
    ): void {
        $this->notificationDispatchService->dispatch(
            users: $this->notificationRecipientResolver->internalUsers(),
            eventKey: $eventKey,
            category: $category,
            title: $title,
            message: $message,
            reservation: $reservation,
            priority: $priority,
            urgent: $urgent
        );
    }

    private function notifyRequester(
        Reservation $reservation,
        string $eventKey,
        string $category,
        string $title,
        string $message,
        string $priority = 'normal',
        bool $urgent = false,
        ?Document $document = null
    ): void {
        $requester = $this->notificationRecipientResolver->reservationRequester($reservation);
        if ($requester === null) {
            return;
        }

        $this->notificationDispatchService->dispatch(
            users: collect([$requester]),
            eventKey: $eventKey,
            category: $category,
            title: $title,
            message: $message,
            reservation: $reservation,
            document: $document,
            priority: $priority,
            urgent: $urgent
        );
    }

    private function notifyDriver(
        Reservation $reservation,
        string $eventKey,
        string $category,
        string $title,
        string $message,
        string $priority = 'normal',
        bool $urgent = false
    ): void {
        $driverUser = $this->notificationRecipientResolver->reservationDriverUser($reservation);
        if ($driverUser === null) {
            return;
        }

        $document = $urgent ? $this->latestRouteSheetDocumentForReservation($reservation) : null;

        $this->notificationDispatchService->dispatch(
            users: collect([$driverUser]),
            eventKey: $eventKey,
            category: $category,
            title: $title,
            message: $message,
            reservation: $reservation,
            document: $document,
            priority: $priority,
            urgent: $urgent
        );
    }

    private function ensureCancellationDeadlineNotPassed(Reservation $reservation, User $actor): void
    {
        $departureAt = $reservation->departureAt();

        if ($departureAt === null) {
            return;
        }

        $isInternal = $actor->hasAnyRole(['admin', 'agent_communal']);

        if ($isInternal) {
            $minDaysBefore = (int) config('reservations.cancellation_commune_min_days_before', 1);
            $deadline = CarbonImmutable::parse($reservation->trip_date->toDateString())->subDays($minDaysBefore - 1);

            // Jours comparés à Bruxelles : le jour J commence à minuit heure belge, pas UTC
            if (CarbonImmutable::parse(Reservation::localToday())->gte($deadline)) {
                throw ValidationException::withMessages([
                    'reservation' => 'La commune ne peut plus annuler cette réservation : le délai d’annulation est dépassé (jusqu’à la veille du trajet).',
                ]);
            }
        } else {
            $minHoursBefore = (int) config('reservations.cancellation_requester_min_hours_before', 24);
            $deadline = $departureAt->subHours($minHoursBefore);

            if (CarbonImmutable::now()->gte($deadline)) {
                throw ValidationException::withMessages([
                    'reservation' => "L’annulation n’est plus possible : le délai de {$minHoursBefore}h avant le départ est dépassé.",
                ]);
            }
        }
    }

    private function isLastMinuteCancellation(Reservation $reservation): bool
    {
        $departureAt = $reservation->departureAt();

        if ($departureAt === null) {
            return false;
        }

        $threshold = now()->addHours((int) config('notifications.last_minute_cancellation_hours', 24));

        return $departureAt->lessThanOrEqualTo($threshold);
    }

    private function latestRouteSheetDocumentForReservation(Reservation $reservation): ?Document
    {
        return Document::query()
            ->where('document_type', DocumentType::FEUILLE_ROUTE->value)
            ->where('is_available', true)
            ->whereHas('routeSheetReservations', fn ($query) => $query->whereKey($reservation->id))
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }

    private function latestDeliberationDocumentForReservation(Reservation $reservation): ?Document
    {
        return Document::query()
            ->where('reservation_id', $reservation->id)
            ->where('document_type', DocumentType::DELIBERATION->value)
            ->where('is_available', true)
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }
}
