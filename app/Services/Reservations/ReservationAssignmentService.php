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
use App\Services\Audit\DomainEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère l'affectation des chauffeurs et véhicules aux réservations.
 *
 * `autoAssign()` : sélectionne le premier chauffeur actif disponible (tri par id)
 * et le plus petit véhicule actif ayant la capacité suffisante (passagers + 1 chauffeur).
 * Si aucun n'est trouvé, crée un conflit NO_DRIVER_AVAILABLE / NO_VEHICLE_AVAILABLE.
 *
 * `assign()` : affectation manuelle avec vérification de disponibilité ; rejette si
 * le chauffeur ou le véhicule est occupé sur la fenêtre d'occupation.
 *
 * La disponibilité combine deux sources :
 *   1. Indisponibilités explicites (unavailabilities, is_active = true).
 *   2. Réservations APPROUVE/CONFIRME dont la fenêtre d'occupation chevauche le créneau.
 *
 * `overlappingReservationsQuery()` réplique le calcul de fenêtre en SQL (TIMESTAMPADD)
 * pour éviter de charger toutes les réservations du jour en mémoire.
 *
 * Affectations simultanées : `autoAssign()` et `assign()` passent par `withDayLock()`, un verrou nommé MariaDB par jour
 * de trajet (GET_LOCK, attente de config('reservations.assignment_lock_wait_seconds') secondes, puis 409). Deux
 * agents qui approuvent au même moment sur le dernier véhicule libre passent l'un après l'autre : le second voit le
 * véhicule pris (conflit « aucun véhicule disponible ») au lieu d'une double affectation. Le verrou nommé reste
 * visible des autres requêtes même à l'intérieur d'une transaction (contrairement à un verrou de cache en base), et les
 * lectures des ressources occupées sont verrouillantes (`sharedLock`) : elles voient la dernière version enregistrée et
 * attendent une affectation encore en cours sur les mêmes réservations. Deux jours différents ne se gênent pas.
 */
class ReservationAssignmentService
{
    /** Préfixe du verrou nommé des affectations d'un jour (withDayLock) */
    public const DAY_LOCK_PREFIX = 'bus-communal:affectations:';

    public function __construct(
        private readonly ReservationOccupationService $occupationService,
        private readonly DomainEventLogger $eventLogger
    ) {}

    public function autoAssign(Reservation $reservation, User $actor): Reservation
    {
        return $this->withDayLock($reservation->trip_date->toDateString(), fn (): Reservation => DB::transaction(function () use ($reservation, $actor): Reservation {
            $window = $this->occupationService->windowForReservation($reservation);
            $driver = $this->availableDriver($reservation, $window);
            $vehicle = $this->availableVehicle($reservation, $window);

            if (! $driver) {
                $this->createConflict($reservation, ReservationConflictType::NO_DRIVER_AVAILABLE, 'Aucun chauffeur disponible pour ce créneau.');
            }

            if (! $vehicle) {
                $this->createConflict($reservation, ReservationConflictType::NO_VEHICLE_AVAILABLE, 'Aucun véhicule disponible pour ce créneau.');
            }

            if ($driver) {
                $this->assignDriver($reservation, $driver, $actor, 'Affectation automatique');
            }

            if ($vehicle) {
                $this->assignVehicle($reservation, $vehicle, $actor, 'Affectation automatique');
            }

            return $reservation->refresh();
        }));
    }

    public function assign(Reservation $reservation, array $data, User $actor): Reservation
    {
        return $this->withDayLock($reservation->trip_date->toDateString(), fn (): Reservation => DB::transaction(function () use ($reservation, $data, $actor): Reservation {
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
        }));
    }

    /**
     * Exécute `$callback` en tenant le verrou des affectations du jour de trajet (verrou nommé MariaDB, réentrant).
     * Verrou toujours pris par une autre requête après l'attente → 409, l'agent réessaie.
     */
    public function withDayLock(string $tripDate, callable $callback): mixed
    {
        $name = self::DAY_LOCK_PREFIX.$tripDate;
        $wait = max(0, (int) config('reservations.assignment_lock_wait_seconds', 5));
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $wait])->acquired ?? 0);

        if ($acquired !== 1) {
            abort(409, 'Une autre affectation est en cours pour ce jour : réessayez dans un instant.');
        }

        try {
            return $callback();
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function availableDriver(Reservation $reservation, ?array $window = null): ?Driver
    {
        $window ??= $this->occupationService->windowForReservation($reservation);

        return $this->freeDriver($window, $reservation->trip_date->toDateString(), $reservation->id);
    }

    public function availableVehicle(Reservation $reservation, ?array $window = null): ?Vehicle
    {
        $window ??= $this->occupationService->windowForReservation($reservation);

        return $this->freeVehicle(
            $window,
            $reservation->trip_date->toDateString(),
            $reservation->nb_children + $reservation->nb_adults + 1,
            $reservation->id
        );
    }

    /**
     * Chauffeur et véhicule encore libres sur une fenêtre, pour une demande pas forcément
     * enregistrée (aperçu de création) : sert à savoir si un chevauchement horaire est réellement
     * bloquant ou si la flotte peut absorber la demande.
     *
     * @return array{driver: ?Driver, vehicle: ?Vehicle}
     */
    public function freeResources(array $window, string $tripDate, int $requiredCapacity, ?int $ignoreReservationId = null): array
    {
        return [
            'driver' => $this->freeDriver($window, $tripDate, $ignoreReservationId),
            'vehicle' => $this->freeVehicle($window, $tripDate, $requiredCapacity, $ignoreReservationId),
        ];
    }

    private function freeDriver(array $window, string $tripDate, ?int $ignoreReservationId): ?Driver
    {
        $busyIds = $this->busyDriverIds($ignoreReservationId, $tripDate, $window);

        return Driver::query()
            ->where('is_active', true)
            ->when($busyIds !== [], fn ($q) => $q->whereNotIn('id', $busyIds))
            ->orderBy('id')
            ->first();
    }

    private function freeVehicle(array $window, string $tripDate, int $requiredCapacity, ?int $ignoreReservationId): ?Vehicle
    {
        $busyIds = $this->busyVehicleIds($ignoreReservationId, $tripDate, $window);

        return Vehicle::query()
            ->where('is_active', true)
            ->where('capacity', '>=', $requiredCapacity)
            ->when($busyIds !== [], fn ($q) => $q->whereNotIn('id', $busyIds))
            ->orderBy('capacity')
            ->orderBy('id')
            ->first();
    }

    public function hasRequiredAssignments(Reservation $reservation): bool
    {
        return filled($reservation->driver_id) && filled($reservation->vehicle_id);
    }

    private function assignDriver(Reservation $reservation, Driver $driver, User $actor, ?string $reason): void
    {
        $oldDriverId = $reservation->driver_id;
        $reservation->update(['driver_id' => $driver->id]);

        $reservation->conflicts()
            ->where('type', ReservationConflictType::NO_DRIVER_AVAILABLE)
            ->where('is_resolved', false)
            ->update(['is_resolved' => true, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now()]);

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

        $this->eventLogger->log(
            aggregateType: 'reservation',
            aggregateId: $reservation->id,
            eventType: 'reservation.driver_assigned',
            actor: $actor,
            oldValues: ['driver_id' => $oldDriverId],
            newValues: ['driver_id' => $driver->id],
            context: ['reason' => $reason]
        );
    }

    private function assignVehicle(Reservation $reservation, Vehicle $vehicle, User $actor, ?string $reason): void
    {
        $oldVehicleId = $reservation->vehicle_id;
        $reservation->update(['vehicle_id' => $vehicle->id]);

        $reservation->conflicts()
            ->where('type', ReservationConflictType::NO_VEHICLE_AVAILABLE)
            ->where('is_resolved', false)
            ->update(['is_resolved' => true, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now()]);

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

        $this->eventLogger->log(
            aggregateType: 'reservation',
            aggregateId: $reservation->id,
            eventType: 'reservation.vehicle_assigned',
            actor: $actor,
            oldValues: ['vehicle_id' => $oldVehicleId],
            newValues: ['vehicle_id' => $vehicle->id],
            context: ['reason' => $reason]
        );
    }

    private function ensureDriverAvailable(Reservation $reservation, Driver $driver): void
    {
        if (! $driver->is_active || ! $this->isDriverAvailable($reservation, $driver)) {
            throw ValidationException::withMessages([
                'driver_id' => 'Ce chauffeur n’est pas disponible pour ce créneau.',
            ]);
        }
    }

    private function ensureVehicleAvailable(Reservation $reservation, Vehicle $vehicle): void
    {
        $requiredCapacity = $reservation->nb_children + $reservation->nb_adults + 1;

        if (! $vehicle->is_active || $vehicle->capacity < $requiredCapacity || ! $this->isVehicleAvailable($reservation, $vehicle)) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Ce véhicule n’est pas disponible ou sa capacité est insuffisante.',
            ]);
        }
    }

    private function isDriverAvailable(Reservation $reservation, Driver $driver): bool
    {
        $window = $this->occupationService->windowForReservation($reservation);

        if (in_array($driver->id, $this->busyDriverIds($reservation->id, $reservation->trip_date->toDateString(), $window), true)) {
            return false;
        }

        return true;
    }

    private function isVehicleAvailable(Reservation $reservation, Vehicle $vehicle): bool
    {
        $window = $this->occupationService->windowForReservation($reservation);

        if (in_array($vehicle->id, $this->busyVehicleIds($reservation->id, $reservation->trip_date->toDateString(), $window), true)) {
            return false;
        }

        return true;
    }

    private function busyDriverIds(?int $ignoreReservationId, string $tripDate, array $window): array
    {
        $fromUnavailabilities = Unavailability::query()
            ->where('is_active', true)
            ->whereNotNull('driver_id')
            ->where('start_datetime', '<', $window['end'])
            ->where('end_datetime', '>', $window['start'])
            ->sharedLock()
            ->pluck('driver_id')
            ->all();

        $fromReservations = $this->overlappingReservationsQuery($ignoreReservationId, $tripDate, $window)
            ->whereNotNull('driver_id')
            ->pluck('driver_id')
            ->all();

        return array_values(array_unique(array_merge($fromUnavailabilities, $fromReservations)));
    }

    private function busyVehicleIds(?int $ignoreReservationId, string $tripDate, array $window): array
    {
        $fromUnavailabilities = Unavailability::query()
            ->where('is_active', true)
            ->whereNotNull('vehicle_id')
            ->where('start_datetime', '<', $window['end'])
            ->where('end_datetime', '>', $window['start'])
            ->sharedLock()
            ->pluck('vehicle_id')
            ->all();

        $fromReservations = $this->overlappingReservationsQuery($ignoreReservationId, $tripDate, $window)
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->all();

        return array_values(array_unique(array_merge($fromUnavailabilities, $fromReservations)));
    }

    /**
     * Reservations on the same day whose occupation window overlaps the given window.
     * Replicates OccupationService::overlaps() in SQL to avoid loading all reservations in memory.
     */
    private function overlappingReservationsQuery(?int $ignoreReservationId, string $tripDate, array $window): \Illuminate\Database\Eloquent\Builder
    {
        $pickupBuffer = $window['pickup_buffer_min'];
        $postReturnBuffer = $window['post_return_buffer_min'];

        return Reservation::query()
            ->when($ignoreReservationId !== null, fn ($q) => $q->whereKeyNot($ignoreReservationId))
            ->whereDate('trip_date', $tripDate)
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
            // Lecture verrouillante : voit les affectations déjà enregistrées par une autre requête (voir withDayLock)
            ->sharedLock()
            // other.window_start < candidate.window_end
            ->whereRaw(
                'TIMESTAMPADD(MINUTE, ?, TIMESTAMP(trip_date, departure_time)) < ?',
                [-$pickupBuffer, $window['end']->toDateTimeString()]
            )
            // other.window_end > candidate.window_start
            ->whereRaw(
                'TIMESTAMPADD(MINUTE, COALESCE(estimated_duration_min, 0) + ?, TIMESTAMP(trip_date, return_time)) > ?',
                [$postReturnBuffer, $window['start']->toDateTimeString()]
            );
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
