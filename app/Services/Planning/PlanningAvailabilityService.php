<?php

namespace App\Services\Planning;

use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Unavailability;
use App\Models\Vehicle;
use App\Services\Reservations\ReservationOccupationService;
use Illuminate\Support\Collection;

/**
 * Calcule la disponibilité de tous les chauffeurs et véhicules pour une réservation donnée.
 *
 * Retourne deux listes (available / unavailable) pour les chauffeurs et les véhicules,
 * chacune avec le motif d'indisponibilité si applicable :
 *   - Chauffeur/véhicule inactif
 *   - Indisponibilité explicite sur ce créneau (table unavailabilities)
 *   - Déjà affecté à une autre réservation APPROUVE/CONFIRME sur ce créneau
 *   - Capacité insuffisante (véhicules uniquement)
 *
 * Les fenêtres d'occupation sont pré-calculées pour toutes les réservations chevauchantes
 * afin d'éviter N appels à ReservationOccupationService::windowForReservation().
 * Réservé aux rôles internes (injecté par PlanningService uniquement si include_availability).
 */
class PlanningAvailabilityService
{
    public function __construct(
        private readonly ReservationOccupationService $occupationService
    ) {}

    public function forReservation(Reservation $reservation): array
    {
        return $this->forReservations(collect([$reservation]))[$reservation->id];
    }

    /**
     * Disponibilités de plusieurs réservations (un planning entier) : [id de réservation => disponibilités].
     * Tout est lu en 5 requêtes, quel que soit le nombre de trajets (chauffeurs, véhicules, indisponibilités
     * de la période, réservations affectées des jours concernés), puis comparé en mémoire pour chaque trajet.
     */
    public function forReservations(Collection $reservations): array
    {
        if ($reservations->isEmpty()) {
            return [];
        }

        $windows = $reservations->mapWithKeys(
            fn (Reservation $reservation) => [$reservation->id => $this->occupationService->windowForReservation($reservation)]
        );
        // Heures locales sans fuseau (comme en base) : comparées en texte, exactement comme le ferait la requête SQL
        $periodStart = $windows->min(fn (array $window) => $window['start']->format('Y-m-d H:i:s'));
        $periodEnd = $windows->max(fn (array $window) => $window['end']->format('Y-m-d H:i:s'));

        $drivers = Driver::query()
            ->with('user')
            ->orderBy('id')
            ->get();

        $vehicles = Vehicle::query()
            ->orderBy('capacity')
            ->orderBy('id')
            ->get();

        $unavailabilities = Unavailability::query()
            ->where('is_active', true)
            ->where('start_datetime', '<', $periodEnd)
            ->where('end_datetime', '>', $periodStart)
            ->get(['driver_id', 'vehicle_id', 'start_datetime', 'end_datetime']);

        // Réservations affectées des jours du planning, rangées par jour ; leurs fenêtres d'occupation calculées une fois
        $assignedByDay = Reservation::query()
            ->whereIn('trip_date', $reservations->map(fn (Reservation $reservation) => $reservation->trip_date->toDateString())->unique()->values())
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            ->where(function ($query): void {
                $query->whereNotNull('driver_id')
                    ->orWhereNotNull('vehicle_id');
            })
            // Ordre fixe : le motif « Déjà affecté à la réservation #… » nomme la première qui chevauche
            ->orderBy('departure_time')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Reservation $reservation) => $reservation->trip_date->toDateString());
        $overlapWindowsByReservationId = $assignedByDay->flatten()->mapWithKeys(
            fn (Reservation $reservation) => [$reservation->id => $windows[$reservation->id] ?? $this->occupationService->windowForReservation($reservation)]
        )->all();

        $availability = [];
        foreach ($reservations as $reservation) {
            $window = $windows[$reservation->id];
            $start = $window['start']->format('Y-m-d H:i:s');
            $end = $window['end']->format('Y-m-d H:i:s');

            $overlapping = $unavailabilities->filter(
                fn (Unavailability $unavailability) => $unavailability->getRawOriginal('start_datetime') < $end
                    && $unavailability->getRawOriginal('end_datetime') > $start
            );
            $driverUnavailabilities = $overlapping->pluck('driver_id')->filter()->unique()->values()->all();
            $vehicleUnavailabilities = $overlapping->pluck('vehicle_id')->filter()->unique()->values()->all();

            $overlappingByDriver = [];
            $overlappingByVehicle = [];
            foreach ($assignedByDay[$reservation->trip_date->toDateString()] ?? [] as $overlapReservation) {
                if ($overlapReservation->id === $reservation->id) {
                    continue;
                }

                if ($overlapReservation->driver_id !== null) {
                    $overlappingByDriver[$overlapReservation->driver_id][] = $overlapReservation;
                }

                if ($overlapReservation->vehicle_id !== null) {
                    $overlappingByVehicle[$overlapReservation->vehicle_id][] = $overlapReservation;
                }
            }

            $availability[$reservation->id] = [
                'drivers' => $this->driversForReservation(
                    $reservation,
                    $window,
                    $drivers,
                    $driverUnavailabilities,
                    $overlappingByDriver,
                    $overlapWindowsByReservationId
                ),
                'vehicles' => $this->vehiclesForReservation(
                    $reservation,
                    $window,
                    $vehicles,
                    $vehicleUnavailabilities,
                    $overlappingByVehicle,
                    $overlapWindowsByReservationId
                ),
            ];
        }

        return $availability;
    }

    private function driversForReservation(
        Reservation $reservation,
        array $window,
        Collection $drivers,
        array $driverUnavailabilities,
        array $overlappingByDriver,
        array $overlapWindowsByReservationId
    ): array
    {
        $drivers = $drivers
            ->map(fn (Driver $driver): array => $this->driverAvailability($reservation, $driver, $window, $driverUnavailabilities, $overlappingByDriver, $overlapWindowsByReservationId))
            ->values();

        return [
            'available' => $drivers->where('is_available', true)->values()->all(),
            'unavailable' => $drivers->where('is_available', false)->values()->all(),
        ];
    }

    private function vehiclesForReservation(
        Reservation $reservation,
        array $window,
        Collection $vehicles,
        array $vehicleUnavailabilities,
        array $overlappingByVehicle,
        array $overlapWindowsByReservationId
    ): array
    {
        $vehicles = $vehicles
            ->map(fn (Vehicle $vehicle): array => $this->vehicleAvailability($reservation, $vehicle, $window, $vehicleUnavailabilities, $overlappingByVehicle, $overlapWindowsByReservationId))
            ->values();

        return [
            'available' => $vehicles->where('is_available', true)->values()->all(),
            'unavailable' => $vehicles->where('is_available', false)->values()->all(),
        ];
    }

    private function driverAvailability(
        Reservation $reservation,
        Driver $driver,
        array $window,
        array $driverUnavailabilities,
        array $overlappingByDriver,
        array $overlapWindowsByReservationId
    ): array
    {
        $reason = $this->driverUnavailabilityReason(
            $reservation,
            $driver,
            $window,
            $driverUnavailabilities,
            $overlappingByDriver,
            $overlapWindowsByReservationId
        );

        return [
            'id' => $driver->id,
            'user_id' => $driver->user_id,
            'name' => trim(($driver->user?->first_name ?? '').' '.($driver->user?->last_name ?? '')),
            'licence_number' => $driver->licence_number,
            'is_available' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    private function vehicleAvailability(
        Reservation $reservation,
        Vehicle $vehicle,
        array $window,
        array $vehicleUnavailabilities,
        array $overlappingByVehicle,
        array $overlapWindowsByReservationId
    ): array
    {
        $reason = $this->vehicleUnavailabilityReason(
            $reservation,
            $vehicle,
            $window,
            $vehicleUnavailabilities,
            $overlappingByVehicle,
            $overlapWindowsByReservationId
        );

        return [
            'id' => $vehicle->id,
            'licence_plate' => $vehicle->licence_plate,
            'capacity' => $vehicle->capacity,
            'is_available' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    private function driverUnavailabilityReason(
        Reservation $reservation,
        Driver $driver,
        array $window,
        array $driverUnavailabilities,
        array $overlappingByDriver,
        array $overlapWindowsByReservationId
    ): ?string
    {
        if (! $driver->is_active) {
            return 'Chauffeur inactif.';
        }

        if (in_array($driver->id, $driverUnavailabilities, true)) {
            return 'Chauffeur indisponible sur ce créneau.';
        }

        $overlap = $this->firstOverlap($window, $overlappingByDriver[$driver->id] ?? [], $overlapWindowsByReservationId);

        if ($overlap) {
            return "Déjà affecté à la réservation #{$overlap->id} sur ce créneau.";
        }

        return null;
    }

    private function vehicleUnavailabilityReason(
        Reservation $reservation,
        Vehicle $vehicle,
        array $window,
        array $vehicleUnavailabilities,
        array $overlappingByVehicle,
        array $overlapWindowsByReservationId
    ): ?string
    {
        if (! $vehicle->is_active) {
            return 'Véhicule inactif.';
        }

        $requiredCapacity = $reservation->nb_children + $reservation->nb_adults + 1;

        if ($vehicle->capacity < $requiredCapacity) {
            return "Capacite insuffisante : {$vehicle->capacity} places pour {$requiredCapacity} necessaires.";
        }

        if (in_array($vehicle->id, $vehicleUnavailabilities, true)) {
            return 'Véhicule indisponible sur ce créneau.';
        }

        $overlap = $this->firstOverlap($window, $overlappingByVehicle[$vehicle->id] ?? [], $overlapWindowsByReservationId);

        if ($overlap) {
            return "Déjà affecté à la réservation #{$overlap->id} sur ce créneau.";
        }

        return null;
    }

    private function firstOverlap(array $window, array $candidates, array $overlapWindowsByReservationId): ?Reservation
    {
        foreach ($candidates as $candidate) {
            $candidateWindow = $overlapWindowsByReservationId[$candidate->id] ?? null;

            if ($candidateWindow !== null && $this->occupationService->overlaps($window, $candidateWindow)) {
                return $candidate;
            }
        }

        return null;
    }
}
