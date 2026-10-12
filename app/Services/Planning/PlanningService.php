<?php

namespace App\Services\Planning;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Construit la vue planning pour une période donnée selon le rôle de l'utilisateur.
 *
 * Par défaut, seules les réservations APPROUVE, CONFIRME et EN_CONFLIT sont incluses.
 * Les réservations REFUSE / ANNULE sont volontairement exclues : elles n'ont plus d'impact
 * sur l'organisation du service.
 *
 * `includeAvailability = true` injecte sur chaque réservation l'attribut dynamique
 * `planning_availability` (chauffeurs et véhicules disponibles / indisponibles).
 * Cet attribut n'est accessible qu'aux rôles internes (admin, agent_communal).
 * Il est calculé à la volée par PlanningAvailabilityService et n'est jamais stocké en DB.
 */
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

        $reservations = $this->periodQuery($startDate, $endDate, $statuses, $filters)
            ->visibleTo($user)
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get();

        // Les disponibilités détaillées sont ajoutées uniquement lorsque le contrôleur
        // les a autorisées. En pratique, elles sont réservées aux rôles internes.
        if ($includeAvailability && $user->hasAnyRole(['admin', 'agent_communal'])) {
            // Toutes les réservations en une fois : quelques requêtes pour le planning entier, pas par trajet
            $availability = $this->availabilityService->forReservations($reservations);
            $reservations->each(function (Reservation $reservation) use ($availability): void {
                $reservation->setAttribute('planning_availability', $availability[$reservation->id]);
            });
        }

        return $reservations;
    }

    /**
     * Planning de tout le service, sans filtre de visibilité : envoi automatique du planning hebdomadaire
     * (WeeklyPlanningService), qui n'a pas d'utilisateur. Mêmes relations et même tri que forPeriod().
     */
    public function forService(string $startDate, string $endDate, array $statuses): Collection
    {
        return $this->periodQuery($startDate, $endDate, $statuses, [])
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get();
    }

    private function periodQuery(string $startDate, string $endDate, array $statuses, array $filters): Builder
    {
        return Reservation::query()
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
            ->when(isset($filters['organization_id']), fn ($query) => $query->where('organization_id', $filters['organization_id']));
    }
}
