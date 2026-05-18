<?php

namespace App\Services\Reservations;

use App\Enums\AssignmentType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\ReservationAssignmentHistory;
use App\Models\ReservationConflict;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Planning\ReservationTimeWindowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationAssignmentService
{
    public function __construct(
        private readonly ReservationTimeWindowService $timeWindowService
    ) {}

    public function autoAssign(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor): Reservation {
            $driver = $this->availableDriver($reservation);
            $vehicle = $this->availableVehicle($reservation);

            if (! $driver) {
                $this->createConflict($reservation, ReservationConflictType::NO_DRIVER_AVAILABLE, 'Aucun chauffeur disponible pour ce creneau.');
            }

            if (! $vehicle) {
                $this->createConflict($reservation, ReservationConflictType::NO_VEHICLE_AVAILABLE, 'Aucun vehicule disponible pour ce creneau.');
            }

            if ($driver) {
                $this->assignDriver($reservation, $driver, $actor, 'Affectation automatique');
            }

            if ($vehicle) {
                $this->assignVehicle($reservation, $vehicle, $actor, 'Affectation automatique');
            }

            return $reservation->refresh();
        });
    }

    public function assign(Reservation $reservation, array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $actor): Reservation {
            if (array_key_exists('driver_id', $data)) {
                $driver = Driver::findOrFail($data['driver_id']);
                $this->ensureDriverAvailable($reservation, $driver);
                $this->assignDriver($reservation, $driver, $actor, $data['reason'] ?? null);
            }

            if (array_key_exists('vehicle_id', $data)) {
                $vehicle = Vehicle::findOrFail($data['vehicle_id']);
                $this->ensureVehicleAvailable($reservation, $vehicle);
                $this->assignVehicle($reservation, $vehicle, $actor, $data['reason'] ?? null);
            }

            return $reservation->refresh();
        });
    }

    public function availableDriver(Reservation $reservation): ?Driver
    {
        return Driver::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (Driver $driver): bool => $this->isDriverAvailable($reservation, $driver));
    }

    public function availableVehicle(Reservation $reservation): ?Vehicle
    {
        $requiredCapacity = $reservation->nb_children + $reservation->nb_adults + 1;

        return Vehicle::query()
            ->where('is_active', true)
            ->where('capacity', '>=', $requiredCapacity)
            ->orderBy('capacity')
            ->orderBy('id')
            ->get()
            ->first(fn (Vehicle $vehicle): bool => $this->isVehicleAvailable($reservation, $vehicle));
    }

    public function hasRequiredAssignments(Reservation $reservation): bool
    {
        return filled($reservation->driver_id) && filled($reservation->vehicle_id);
    }

    private function assignDriver(Reservation $reservation, Driver $driver, User $actor, ?string $reason): void
    {
        $oldDriverId = $reservation->driver_id;
        $reservation->update(['driver_id' => $driver->id]);

        ReservationAssignmentHistory::create([
            'reservation_id' => $reservation->id,
            'assignment_type' => AssignmentType::DRIVER,
            'old_driver_id' => $oldDriverId,
            'new_driver_id' => $driver->id,
            'old_vehicle_id' => $reservation->vehicle_id,
            'new_vehicle_id' => $reservation->vehicle_id,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
        ]);
    }

    private function assignVehicle(Reservation $reservation, Vehicle $vehicle, User $actor, ?string $reason): void
    {
        $oldVehicleId = $reservation->vehicle_id;
        $reservation->update(['vehicle_id' => $vehicle->id]);

        ReservationAssignmentHistory::create([
            'reservation_id' => $reservation->id,
            'assignment_type' => AssignmentType::VEHICLE,
            'old_driver_id' => $reservation->driver_id,
            'new_driver_id' => $reservation->driver_id,
            'old_vehicle_id' => $oldVehicleId,
            'new_vehicle_id' => $vehicle->id,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
        ]);
    }

    private function ensureDriverAvailable(Reservation $reservation, Driver $driver): void
    {
        if (! $driver->is_active || ! $this->isDriverAvailable($reservation, $driver)) {
            throw ValidationException::withMessages([
                'driver_id' => 'Ce chauffeur n est pas disponible pour ce creneau.',
            ]);
        }
    }

    private function ensureVehicleAvailable(Reservation $reservation, Vehicle $vehicle): void
    {
        $requiredCapacity = $reservation->nb_children + $reservation->nb_adults + 1;

        if (! $vehicle->is_active || $vehicle->capacity < $requiredCapacity || ! $this->isVehicleAvailable($reservation, $vehicle)) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Ce vehicule n est pas disponible ou sa capacite est insuffisante.',
            ]);
        }
    }

    private function isDriverAvailable(Reservation $reservation, Driver $driver): bool
    {
        [$start, $end] = $this->timeWindowService->window($reservation);

        $hasUnavailability = Unavailability::query()
            ->where('is_active', true)
            ->where('driver_id', $driver->id)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->exists();

        if ($hasUnavailability) {
            return false;
        }

        return ! Reservation::query()
            ->whereKeyNot($reservation->id)
            ->where('driver_id', $driver->id)
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            ->whereDate('trip_date', $reservation->trip_date)
            ->get()
            ->contains(fn (Reservation $other): bool => $this->timeWindowService->overlaps($reservation, $other));
    }

    private function isVehicleAvailable(Reservation $reservation, Vehicle $vehicle): bool
    {
        [$start, $end] = $this->timeWindowService->window($reservation);

        $hasUnavailability = Unavailability::query()
            ->where('is_active', true)
            ->where('vehicle_id', $vehicle->id)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->exists();

        if ($hasUnavailability) {
            return false;
        }

        return ! Reservation::query()
            ->whereKeyNot($reservation->id)
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            ->whereDate('trip_date', $reservation->trip_date)
            ->get()
            ->contains(fn (Reservation $other): bool => $this->timeWindowService->overlaps($reservation, $other));
    }

    private function createConflict(Reservation $reservation, ReservationConflictType $type, string $message): void
    {
        ReservationConflict::firstOrCreate([
            'reservation_id' => $reservation->id,
            'type' => $type,
            'is_resolved' => false,
        ], [
            'message' => $message,
        ]);
    }
}
