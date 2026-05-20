<?php

namespace App\Services\Planning;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PlanningService
{
    public function __construct(
        private readonly PlanningAvailabilityService $availabilityService
    ) {}

    public function forPeriod(User $user, string $startDate, string $endDate, bool $includeAvailability = false, array $statuses = []): Collection
    {
        $statuses = $statuses !== [] ? $statuses : [
            ReservationStatus::APPROUVE->value,
            ReservationStatus::CONFIRME->value,
            ReservationStatus::EN_CONFLIT->value,
        ];

        $reservations = Reservation::query()
            ->with([
                'organization',
                'requestedByUser',
                'pickupLocation',
                'destinationLocation',
                'driver.user',
                'vehicle',
                'conflicts',
            ])
            ->whereDate('trip_date', '>=', $startDate)
            ->whereDate('trip_date', '<=', $endDate)
            ->whereIn('status', $statuses)
            ->when(! $user->hasAnyRole(['admin', 'agent_communal']), function ($query) use ($user): void {
                $query->where(function ($query) use ($user): void {
                    $query->where('requested_by_user_id', $user->getKey())
                        ->orWhereHas('organization.users', fn ($query) => $query->whereKey($user->getKey()))
                        ->when(
                            $user->hasRole('chauffeur') && $user->driver !== null,
                            fn ($query) => $query->orWhere('driver_id', $user->driver->getKey())
                        );
                });
            })
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get();

        if ($includeAvailability && $user->hasAnyRole(['admin', 'agent_communal'])) {
            $reservations->each(function (Reservation $reservation): void {
                $reservation->setAttribute('planning_availability', $this->availabilityService->forReservation($reservation));
            });
        }

        return $reservations;
    }
}
