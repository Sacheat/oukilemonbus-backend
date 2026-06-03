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

    public function forPeriod(
        User $user,
        string $startDate,
        string $endDate,
        bool $includeAvailability = false,
        array $statuses = [],
        array $filters = []
    ): Collection {
        // Par défaut, le planning affiche uniquement les réservations encore utiles
        // pour l'organisation du service : approuvées, confirmées ou en conflit.
        // Les réservations refusées ou annulées sont volontairement exclues.
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

            // Filtres optionnels de consultation du planning.
            // Ils permettent de construire une vue ciblée par véhicule,
            // par chauffeur ou par organisation.
            ->when(isset($filters['vehicle_id']), fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
            ->when(isset($filters['driver_id']), fn ($query) => $query->where('driver_id', $filters['driver_id']))
            ->when(isset($filters['organization_id']), fn ($query) => $query->where('organization_id', $filters['organization_id']))

            ->visibleTo($user)
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get();

        // Les disponibilités détaillées sont ajoutées uniquement lorsque le contrôleur
        // les a autorisées. En pratique, elles sont réservées aux rôles internes.
        if ($includeAvailability && $user->hasAnyRole(['admin', 'agent_communal'])) {
            $reservations->each(function (Reservation $reservation): void {
                $reservation->setAttribute('planning_availability', $this->availabilityService->forReservation($reservation));
            });
        }

        return $reservations;
    }
}
