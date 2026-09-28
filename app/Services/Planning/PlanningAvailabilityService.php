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
        $window = $this->occupationService->windowForReservation($reservation);
        $start = $window['start'];
        $end = $window['end'];

        $drivers = Driver::query()
            ->with('user')
            ->orderBy('id')
            ->get();

        $vehicles = Vehicle::query()
            ->orderBy('capacity')
            ->orderBy('id')
            ->get();

        $driverUnavailabilities = Unavailability::query()
            ->where('is_active', true)
            ->whereNotNull('driver_id')
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->pluck('driver_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $vehicleUnavailabilities = Unavailability::query()
            ->where('is_active', true)
            ->whereNotNull('vehicle_id')
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->pluck('vehicle_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $overlappingReservations = Reservation::query()
            ->whereKeyNot($reservation->id)
            ->whereDate('trip_date', $reservation->trip_date)
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            ->where(function ($query): void {
                $query->whereNotNull('driver_id')
                    ->orWhereNotNull('vehicle_id');
            })
            ->get();

        $overlapWindowsByReservationId = [];
        $overlappingByDriver = [];
        $overlappingByVehicle = [];

        foreach ($overlappingReservations as $overlapReservation) {
            $overlapWindowsByReservationId[$overlapReservation->id] = $this->occupationService->windowForReservation($overlapReservation);

            if ($overlapReservation->driver_id !== null) {
                $overlappingByDriver[$overlapReservation->driver_id][] = $overlapReservation;
            }

            if ($overlapReservation->vehicle_id !== null) {
                $overlappingByVehicle[$overlapReservation->vehicle_id][] = $overlapReservation;
            }
        }

        return [
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
