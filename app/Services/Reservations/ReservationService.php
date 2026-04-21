<?php

namespace App\Services\Reservations;

use App\Enums\CouncilDecisionStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Models\CouncilDecision;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\ReservationStatusHistory;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        private readonly ReservationPreviewService $previewService,
        private readonly ReservationAssignmentService $assignmentService
    ) {}

    public function create(array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($data, $actor): Reservation {
            $organization = Organization::findOrFail($data['organization_id']);
            $requestedByUserId = $actor->hasAnyRole(['admin', 'agent_communal'])
                ? ($data['requested_by_user_id'] ?? $actor->id)
                : $actor->id;

            if (! Gate::forUser($actor)->allows('createForOrganization', [Reservation::class, $organization])) {
                abort(403);
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

            if ($status === ReservationStatus::APPROUVE) {
                $reservation = $this->assignmentService->autoAssign($reservation, $actor);
                if ($this->assignmentService->hasRequiredAssignments($reservation) && ! $reservation->conflicts()->where('is_resolved', false)->exists()) {
                    $reservation = $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation automatique');
                }
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

            return $reservation->refresh()->load(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts']);
        });
    }

    public function reject(Reservation $reservation, User $actor, string $reason): Reservation
    {
        $reservation->update([
            'status' => ReservationStatus::REFUSE,
            'rejection_reason' => $reason,
        ]);

        $this->recordStatus($reservation, $reservation->getOriginal('status'), ReservationStatus::REFUSE, $actor, $reason);

        return $reservation->refresh();
    }

    public function cancel(Reservation $reservation, User $actor, string $reason): Reservation
    {
        $reservation->update([
            'status' => ReservationStatus::ANNULE,
            'cancelled_by_user_id' => $actor->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->recordStatus($reservation, $reservation->getOriginal('status'), ReservationStatus::ANNULE, $actor, $reason);

        return $reservation->refresh();
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

        return $this->transition($reservation, ReservationStatus::CONFIRME, $actor, 'Confirmation');
    }

    public function recordCouncilDecision(Reservation $reservation, array $data, User $actor): Reservation
    {
        $decision = CouncilDecision::updateOrCreate([
            'reservation_id' => $reservation->id,
        ], [
            'decision_status' => $data['decision_status'],
            'decision_date' => $data['decision_date'] ?? null,
            'recorded_by_user_id' => $actor->id,
            'decision_reference' => $data['decision_reference'] ?? null,
            'validated_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        if ($decision->decision_status === CouncilDecisionStatus::REFUSE) {
            return $this->reject($reservation, $actor, $data['notes'] ?? 'Decision defavorable du college');
        }

        if ($decision->decision_status === CouncilDecisionStatus::APPROUVE) {
            $this->resolveCouncilDerogationConflicts($reservation, $actor);
        }

        return $reservation->refresh()->load('councilDecision');
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
}
