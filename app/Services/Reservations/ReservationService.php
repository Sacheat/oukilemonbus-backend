<?php

namespace App\Services\Reservations;

use App\Enums\CouncilDecisionStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\ReservationStatusHistory;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\Logging\DomainLogger;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    private DomainLogger $logger;

    public function __construct(
        private readonly ReservationPreviewService $previewService,
        private readonly ReservationAssignmentService $assignmentService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver,
        private readonly DomainEventLogger $eventLogger,
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
                throw new AuthorizationException('Action non autorisee pour cette organisation.');
            }

            $preview = $this->previewService->preview($data);
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
                ]),
                'requested_by_user_id' => $requestedByUserId,
                'distance_km' => $preview['distance_km'],
                'estimated_duration_min' => $preview['estimated_duration_min'],
                'status' => $status,
                'approval_type' => $preview['approval_type'],
                'validated_by_user_id' => $status === ReservationStatus::APPROUVE ? $actor->id : null,
                'validated_at' => $status === ReservationStatus::APPROUVE ? now() : null,
            ]);

            $this->recordStatus($reservation, null, $status, $actor, 'Creation de la demande');
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
                    'Conflit de reservation detecte',
                    "La reservation #{$reservation->id} a ete creee avec un conflit.",
                    $reservation
                );
            }

            return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
        });
    }

    public function update(Reservation $reservation, array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $actor): Reservation {
            $payload = array_merge($reservation->only([
                'organization_id',
                'pickup_location_id',
                'destination_location_id',
                'trip_date',
                'departure_time',
                'return_time',
                'nb_children',
                'nb_adults',
                'trip_purpose',
            ]), $data, ['ignore_reservation_id' => $reservation->id]);

            $preview = $this->previewService->preview($payload);
            $newStatus = count($preview['conflicts']) > 0 ? ReservationStatus::EN_CONFLIT : $reservation->status;

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

            if ($newStatus !== $reservation->getOriginal('status')) {
                $this->recordStatus($reservation, $reservation->getOriginal('status'), $newStatus, $actor, 'Modification de la demande');
            }

            $reservation->conflicts()->delete();
            $this->syncConflicts($reservation, $preview['conflicts']);
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

    public function approve(Reservation $reservation, User $actor, ?string $reason = null): Reservation
    {
        $this->ensureCouncilDecisionAllowsApproval($reservation);

        return DB::transaction(function () use ($reservation, $actor, $reason): Reservation {
            $reservation = $this->transition($reservation, ReservationStatus::APPROUVE, $actor, $reason ?? 'Approbation');
            $reservation = $this->assignmentService->autoAssign($reservation, $actor);

            if ($this->assignmentService->hasRequiredAssignments($reservation) && ! $reservation->conflicts()->where('is_resolved', false)->exists()) {
                $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation apres approbation');
            }

            $this->notifyRequester(
                $reservation,
                'reservation.approved',
                'reservation_updates',
                'Reservation approuvee',
                "Votre reservation #{$reservation->id} a ete approuvee."
            );

            return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
        });
    }

    public function reject(Reservation $reservation, User $actor, string $reason): Reservation
    {
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
                'Reservation refusee',
                "Votre reservation #{$reservation->id} a ete refusee. Motif: {$reason}"
            );

            return $reservation->refresh();
        });
    }

    public function cancel(Reservation $reservation, User $actor, string $reason): Reservation
    {
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
                'Reservation annulee',
                "Votre reservation #{$reservation->id} a ete annulee. Motif: {$reason}",
                $priority,
                $isLastMinute
            );

            $this->notifyDriver(
                $reservation,
                'reservation.cancelled',
                $category,
                'Annulation de reservation',
                "La reservation #{$reservation->id} a ete annulee. Motif: {$reason}",
                $priority,
                $isLastMinute
            );

            return $reservation->refresh();
        });
    }

    public function confirm(Reservation $reservation, User $actor): Reservation
    {
        if (! $this->assignmentService->hasRequiredAssignments($reservation)) {
            throw ValidationException::withMessages([
                'reservation' => 'La reservation doit avoir un chauffeur et un vehicule assignes.',
            ]);
        }

        if ($reservation->conflicts()->where('is_resolved', false)->exists()) {
            throw ValidationException::withMessages([
                'reservation' => 'Tous les conflits doivent etre resolus avant confirmation.',
            ]);
        }

        $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation');

        $this->notifyRequester(
            $reservation,
            'reservation.confirmed',
            'reservation_updates',
            'Reservation confirmee',
            "Votre reservation #{$reservation->id} est confirmee."
        );

        $this->notifyDriver(
            $reservation,
            'reservation.confirmed',
            'driver_planning',
            'Mission confirmee',
            "La mission liee a la reservation #{$reservation->id} est confirmee."
        );

        return $reservation;
    }

    public function resolveConflict(ReservationConflict $conflict, User $actor): ReservationConflict
    {
        $conflict->update([
            'is_resolved' => true,
            'resolved_by_user_id' => $actor->id,
            'resolved_at' => now(),
        ]);

        return $conflict->refresh();
    }

    private function ensureCouncilDecisionAllowsApproval(Reservation $reservation): void
    {
        if ($reservation->organization->type === OrganizationType::ECOLE_COMMUNALE) {
            return;
        }

        if ($reservation->councilDecision?->decision_status !== CouncilDecisionStatus::APPROUVE) {
            throw ValidationException::withMessages([
                'reservation' => 'Une decision favorable du college est obligatoire avant approbation.',
            ]);
        }
    }

    private function transition(Reservation $reservation, ReservationStatus $newStatus, User $actor, string $reason): Reservation
    {
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
        bool $urgent = false
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

    private function isLastMinuteCancellation(Reservation $reservation): bool
    {
        $tripDate = $reservation->trip_date?->toDateString();
        $departureTime = $reservation->departure_time?->format('H:i:s');

        if ($tripDate === null || $departureTime === null) {
            return false;
        }

        $departureAt = CarbonImmutable::parse($tripDate.' '.$departureTime);
        $threshold = now()->addHours((int) config('notifications.last_minute_cancellation_hours', 24));

        return $departureAt->lessThanOrEqualTo($threshold);
    }

    private function latestRouteSheetDocumentForReservation(Reservation $reservation): ?\App\Models\Document
    {
        return \App\Models\Document::query()
            ->where('document_type', \App\Enums\DocumentType::FEUILLE_ROUTE->value)
            ->where('is_available', true)
            ->whereHas('routeSheetReservations', fn ($query) => $query->whereKey($reservation->id))
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }
}
