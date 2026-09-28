<?php

namespace App\Policies;

use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\CouncilDecision;
use App\Models\Reservation;
use App\Models\User;

/**
 * Contrôle l'accès aux décisions du collège communal.
 *
 * view() : les internes voient toutes les décisions ; les externes (requester,
 *          membre de l'organisation, chauffeur assigné) voient celles liées à leurs
 *          réservations — miroir de ReservationPolicy::view().
 *
 * create() / update() : réservés aux internes, avec deux gardes supplémentaires :
 *   - L'organisation ne doit pas être isCouncilDecisionExempt() (ex. les écoles).
 *   - Le statut de la réservation doit être EN_ATTENTE, EN_CONFLIT ou APPROUVE.
 */
class CouncilDecisionPolicy
{
    private const array INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('council-decisions.view');
    }

    public function view(User $user, CouncilDecision $councilDecision): bool
    {
        if (! $user->can('council-decisions.view')) {
            return false;
        }

        $reservation = $councilDecision->reservation;

        if ($this->isInternal($user)) {
            return true;
        }

        if ($reservation->requested_by_user_id === $user->getKey()) {
            return true;
        }

        if ($user->isMemberOf($reservation->organization_id)) {
            return true;
        }

        return $user->hasRole('chauffeur')
            && $user->driver !== null
            && $reservation->driver_id === $user->driver->getKey();
    }

    public function create(User $user, Reservation $reservation): bool
    {
        return $user->can('council-decisions.create')
            && $this->isInternal($user)
            && ! $reservation->organization->type?->isCouncilDecisionExempt()
            && $this->hasAllowedReservationStatus($reservation);
    }

    public function update(User $user, CouncilDecision $councilDecision): bool
    {
        return $user->can('council-decisions.update')
            && $this->isInternal($user)
            && ! $councilDecision->reservation->organization->type?->isCouncilDecisionExempt()
            && $this->hasAllowedReservationStatus($councilDecision->reservation);
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }

    private function hasAllowedReservationStatus(Reservation $reservation): bool
    {
        $status = $reservation->status instanceof ReservationStatus
            ? $reservation->status->value
            : $reservation->status;

        return in_array($status, [
            ReservationStatus::EN_ATTENTE->value,
            ReservationStatus::EN_CONFLIT->value,
            ReservationStatus::APPROUVE->value,
        ], true);
    }
}
