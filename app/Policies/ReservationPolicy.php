<?php

namespace App\Policies;

use App\Enums\ReservationStatus;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    private const array INTERNAL_ROLES = ['admin', 'agent_communal'];

    public function viewAny(User $user): bool
    {
        return $user->can('reservations.view');
    }

    public function view(User $user, Reservation $reservation): bool
    {
        if (! $user->can('reservations.view')) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        if ($reservation->requested_by_user_id === $user->getKey()) {
            return true;
        }

        if ($user->hasRole('responsable_organisation') && $this->belongsToUserOrganization($user, $reservation->organization_id)) {
            return true;
        }

        return $user->hasRole('chauffeur')
            && $user->driver !== null
            && $reservation->driver_id === $user->driver->getKey();
    }

    public function preview(User $user): bool
    {
        return $user->can('reservations.create');
    }

    public function create(User $user): bool
    {
        return $user->can('reservations.create');
    }

    public function createForOrganization(User $user, Organization $organization): bool
    {
        if (! $this->create($user)) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        return $this->belongsToUserOrganization($user, $organization->getKey());
    }

    public function update(User $user, Reservation $reservation): bool
    {
        if (! $user->can('reservations.update') || $this->isFinalStatus($reservation)) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        if (! $this->isUserEditableStatus($reservation)) {
            return false;
        }

        if ($user->hasRole('responsable_organisation') && $this->belongsToUserOrganization($user, $reservation->organization_id)) {
            return true;
        }

        return $reservation->requested_by_user_id === $user->getKey();
    }

    public function approve(User $user, Reservation $reservation): bool
    {
        return $user->can('reservations.approve')
            && $this->isInternal($user)
            && $this->hasStatus($reservation, [
                ReservationStatus::EN_ATTENTE->value,
                ReservationStatus::EN_CONFLIT->value,
            ]);
    }

    public function reject(User $user, Reservation $reservation): bool
    {
        return $user->can('reservations.reject')
            && $this->isInternal($user)
            && ! $this->hasStatus($reservation, [
                ReservationStatus::REFUSE->value,
                ReservationStatus::ANNULE->value,
                ReservationStatus::CONFIRME->value,
            ]);
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        if (! $user->can('reservations.cancel') || ! $this->isActiveStatus($reservation)) {
            return false;
        }

        if ($this->isInternal($user)) {
            return true;
        }

        if ($user->hasRole('responsable_organisation') && $this->belongsToUserOrganization($user, $reservation->organization_id)) {
            return true;
        }

        return $reservation->requested_by_user_id === $user->getKey();
    }

    public function assign(User $user, Reservation $reservation): bool
    {
        return $user->can('reservations.assign')
            && $this->isInternal($user)
            && ! $this->isFinalStatus($reservation);
    }

    public function confirm(User $user, Reservation $reservation): bool
    {
        return $user->can('reservations.confirm')
            && $this->isInternal($user)
            && $this->hasStatus($reservation, [ReservationStatus::APPROUVE->value]);
    }

    public function viewConflicts(User $user, Reservation $reservation): bool
    {
        return $user->can('reservations.manage-conflicts')
            && $this->isInternal($user);
    }

    public function resolveConflict(User $user, Reservation $reservation): bool
    {
        return $user->can('reservations.manage-conflicts')
            && $this->isInternal($user)
            && $this->isActiveStatus($reservation);
    }

    private function isInternal(User $user): bool
    {
        return $user->hasAnyRole(self::INTERNAL_ROLES);
    }

    private function belongsToUserOrganization(User $user, mixed $organizationId): bool
    {
        if ($organizationId === null) {
            return false;
        }

        return $user->organizations()
            ->whereKey($organizationId)
            ->exists();
    }

    private function isUserEditableStatus(Reservation $reservation): bool
    {
        return $this->hasStatus($reservation, [
            ReservationStatus::EN_ATTENTE->value,
            ReservationStatus::EN_CONFLIT->value,
        ]);
    }

    private function isActiveStatus(Reservation $reservation): bool
    {
        return $this->hasStatus($reservation, [
            ReservationStatus::EN_ATTENTE->value,
            ReservationStatus::EN_CONFLIT->value,
            ReservationStatus::APPROUVE->value,
            ReservationStatus::CONFIRME->value,
        ]);
    }

    private function isFinalStatus(Reservation $reservation): bool
    {
        return $this->hasStatus($reservation, [
            ReservationStatus::REFUSE->value,
            ReservationStatus::ANNULE->value,
        ]);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function hasStatus(Reservation $reservation, array $statuses): bool
    {
        $status = $reservation->status instanceof ReservationStatus
            ? $reservation->status->value
            : $reservation->status;

        return in_array($status, $statuses, true);
    }
}
