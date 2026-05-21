<?php

namespace App\Services\Planning;

use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Unavailability;
use App\Models\Vehicle;
use App\Services\Reservations\ReservationOccupationService;

class PlanningAvailabilityService
{
    public function __construct(
        private readonly ReservationOccupationService $occupationService
    ) {}

    public function forReservation(Reservation $reservation): array
    {
        return [
            'drivers' => $this->driversForReservation($reservation),
            'vehicles' => $this->vehiclesForReservation($reservation),
        ];
    }

    private function driversForReservation(Reservation $reservation): array
    {
        $drivers = Driver::query()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (Driver $driver): array => $this->driverAvailability($reservation, $driver))
            ->values();

        return [
            'available' => $drivers->where('is_available', true)->values()->all(),
            'unavailable' => $drivers->where('is_available', false)->values()->all(),
        ];
    }

    private function vehiclesForReservation(Reservation $reservation): array
    {
        $vehicles = Vehicle::query()
            ->orderBy('capacity')
            ->orderBy('id')
            ->get()
            ->map(fn (Vehicle $vehicle): array => $this->vehicleAvailability($reservation, $vehicle))
            ->values();

        return [
            'available' => $vehicles->where('is_available', true)->values()->all(),
            'unavailable' => $vehicles->where('is_available', false)->values()->all(),
        ];
    }

    private function driverAvailability(Reservation $reservation, Driver $driver): array
    {
        $reason = $this->driverUnavailabilityReason($reservation, $driver);

        return [
            'id' => $driver->id,
            'user_id' => $driver->user_id,
            'name' => trim(($driver->user?->first_name ?? '').' '.($driver->user?->last_name ?? '')),
            'licence_number' => $driver->licence_number,
            'is_available' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    private function vehicleAvailability(Reservation $reservation, Vehicle $vehicle): array
    {
        $reason = $this->vehicleUnavailabilityReason($reservation, $vehicle);

        return [
            'id' => $vehicle->id,
            'licence_plate' => $vehicle->licence_plate,
            'capacity' => $vehicle->capacity,
            'is_available' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    private function driverUnavailabilityReason(Reservation $reservation, Driver $driver): ?string
    {
        if (! $driver->is_active) {
            return 'Chauffeur inactif.';
        }

        $window = $this->occupationService->windowForReservation($reservation);
        $start = $window['start'];
        $end = $window['end'];

        if (Unavailability::query()
            ->where('is_active', true)
            ->where('driver_id', $driver->id)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->exists()) {
            return 'Chauffeur indisponible sur ce creneau.';
        }

        $overlap = Reservation::query()
            ->whereKeyNot($reservation->id)
            ->where('driver_id', $driver->id)
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            ->whereDate('trip_date', $reservation->trip_date)
            ->get()
            ->first(fn (Reservation $other): bool => $this->occupationService->overlaps(
                $window,
                $this->occupationService->windowForReservation($other)
            ));

        if ($overlap) {
            return "Deja affecte a la reservation #{$overlap->id} sur ce creneau.";
        }

        return null;
    }

    private function vehicleUnavailabilityReason(Reservation $reservation, Vehicle $vehicle): ?string
    {
        if (! $vehicle->is_active) {
            return 'Vehicule inactif.';
        }

        $requiredCapacity = $reservation->nb_children + $reservation->nb_adults + 1;

        if ($vehicle->capacity < $requiredCapacity) {
            return "Capacite insuffisante : {$vehicle->capacity} places pour {$requiredCapacity} necessaires.";
        }

        $window = $this->occupationService->windowForReservation($reservation);
        $start = $window['start'];
        $end = $window['end'];

        if (Unavailability::query()
            ->where('is_active', true)
            ->where('vehicle_id', $vehicle->id)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->exists()) {
            return 'Vehicule indisponible sur ce creneau.';
        }

        $overlap = Reservation::query()
            ->whereKeyNot($reservation->id)
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            ->whereDate('trip_date', $reservation->trip_date)
            ->get()
            ->first(fn (Reservation $other): bool => $this->occupationService->overlaps(
                $window,
                $this->occupationService->windowForReservation($other)
            ));

        if ($overlap) {
            return "Deja affecte a la reservation #{$overlap->id} sur ce creneau.";
        }

        return null;
    }
}
