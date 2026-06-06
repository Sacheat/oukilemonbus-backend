<?php

namespace App\Services\Unavailabilities;

use App\Enums\UnavailabilityReasonType;
use App\Exceptions\UnavailabilityException;
use App\Models\Unavailability;
use App\Models\UnavailabilityHistory;
use App\Models\User;
use App\Services\Reservations\ReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UnavailabilityService
{
    public function __construct(
        private readonly ReservationService $reservationService
    ) {}
    /**
     * Verifie que le sujet de l'indisponibilite est coherent avec son type
     * Un chauffeur pour type driver, un vehicule pour type vehicle — jamais les deux
     *
     * @throws UnavailabilityException
     */
    private function validateSubjectAssignment(UnavailabilityReasonType $reasonType, ?int $driverId, ?int $vehicleId): void
    {
        if ($reasonType === UnavailabilityReasonType::DRIVER) {
            if (blank($driverId)) {
                throw new UnavailabilityException(
                    'Un driver_id est obligatoire pour une indisponibilite de type driver.'
                );
            }
            if (filled($vehicleId)) {
                throw new UnavailabilityException(
                    'vehicle_id doit etre null pour une indisponibilite de type driver.'
                );
            }
        }

        if ($reasonType === UnavailabilityReasonType::VEHICLE) {
            if (blank($vehicleId)) {
                throw new UnavailabilityException(
                    'Un vehicle_id est obligatoire pour une indisponibilite de type vehicle.'
                );
            }
            if (filled($driverId)) {
                throw new UnavailabilityException(
                    'driver_id doit etre null pour une indisponibilite de type vehicle.'
                );
            }
        }
    }

    /**
     * @throws UnavailabilityException
     */
    private function validateDateRange(mixed $startDatetime, mixed $endDatetime): void
    {
        if (Carbon::parse($endDatetime)->lessThanOrEqualTo(Carbon::parse($startDatetime))) {
            throw new UnavailabilityException(
                'La date de fin doit etre apres la date de debut.'
            );
        }
    }

    /**
     * @throws UnavailabilityException
     */
    public function create(array $data, User $actor): Unavailability
    {
        $reasonType = UnavailabilityReasonType::from($data['reason_type']);

        $this->validateSubjectAssignment(
            $reasonType,
            $data['driver_id'] ?? null,
            $data['vehicle_id'] ?? null
        );
        $this->validateDateRange($data['start_datetime'], $data['end_datetime']);

        $unavailability = Unavailability::create([
            ...$data,
            'created_by_user_id' => $actor->id,
        ]);

        $this->reservationService->applyUnavailabilityImpact($unavailability->load(['driver.user', 'vehicle']), $actor);

        return $unavailability;
    }

    /**
     * Met à jour une indisponibilité et enregistre l'historique des modifications
     *
     * @throws UnavailabilityException
     */
    public function update(Unavailability $unavailability, array $data, User $actor, ?string $changeReason = null): Unavailability
    {
        $reasonType = isset($data['reason_type'])
            ? UnavailabilityReasonType::from($data['reason_type'])
            : $unavailability->reason_type;

        $driverId = array_key_exists('driver_id', $data) ? $data['driver_id'] : $unavailability->driver_id;
        $vehicleId = array_key_exists('vehicle_id', $data) ? $data['vehicle_id'] : $unavailability->vehicle_id;
        $startDatetime = array_key_exists('start_datetime', $data)
            ? $data['start_datetime']
            : $unavailability->start_datetime;
        $endDatetime = array_key_exists('end_datetime', $data)
            ? $data['end_datetime']
            : $unavailability->end_datetime;

        $this->validateSubjectAssignment($reasonType, $driverId, $vehicleId);
        $this->validateDateRange($startDatetime, $endDatetime);

        $datesChanged = array_key_exists('start_datetime', $data) || array_key_exists('end_datetime', $data);

        return DB::transaction(function () use ($unavailability, $data, $actor, $changeReason, $datesChanged): Unavailability {
            UnavailabilityHistory::create([
                'unavailability_id' => $unavailability->id,
                'old_reason_type' => $unavailability->reason_type,
                'new_reason_type' => $data['reason_type'] ?? $unavailability->reason_type,
                'old_start_datetime' => $unavailability->start_datetime,
                'new_start_datetime' => $data['start_datetime'] ?? $unavailability->start_datetime,
                'old_end_datetime' => $unavailability->end_datetime,
                'new_end_datetime' => $data['end_datetime'] ?? $unavailability->end_datetime,
                'old_reason' => $unavailability->reason,
                'new_reason' => $data['reason'] ?? $unavailability->reason,
                'changed_by_user_id' => $actor->id,
                'change_reason' => $changeReason,
            ]);

            $unavailability->update($data);
            $unavailability->refresh()->load(['driver.user', 'vehicle']);

            if ($datesChanged) {
                $this->reservationService->applyUnavailabilityImpact($unavailability, $actor);
            }

            return $unavailability->refresh();
        });
    }

    /**
     * Annule une indisponibilité (la rend inactive) et enregistre l'historique de cette annulation
     */
    public function cancel(Unavailability $unavailability, User $actor): Unavailability
    {
        return DB::transaction(function () use ($unavailability, $actor): Unavailability {
            UnavailabilityHistory::create([
                'unavailability_id' => $unavailability->id,
                'old_reason_type' => $unavailability->reason_type,
                'new_reason_type' => $unavailability->reason_type,
                'old_start_datetime' => $unavailability->start_datetime,
                'new_start_datetime' => $unavailability->start_datetime,
                'old_end_datetime' => $unavailability->end_datetime,
                'new_end_datetime' => $unavailability->end_datetime,
                'old_reason' => $unavailability->reason,
                'new_reason' => $unavailability->reason,
                'changed_by_user_id' => $actor->id,
                'change_reason' => 'Indisponibilite annulee.',
            ]);

            $unavailability->update(['is_active' => false]);

            return $unavailability->refresh();
        });
    }
}
