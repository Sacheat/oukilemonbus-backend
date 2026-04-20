<?php

namespace App\Services\Unavailabilities;

use App\Enums\UnavailabilityReasonType;
use App\Exceptions\UnavailabilityException;
use App\Models\Unavailability;
use App\Models\UnavailabilityHistory;
use Illuminate\Support\Facades\DB;

class UnavailabilityService
{
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
    public function create(array $data, int $createdByUserId): Unavailability
    {
        $reasonType = UnavailabilityReasonType::from($data['reason_type']);

        $this->validateSubjectAssignment(
            $reasonType,
            $data['driver_id'] ?? null,
            $data['vehicle_id'] ?? null
        );

        return Unavailability::create([
            ...$data,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * Met à jour une indisponibilité et enregistre l'historique des modifications
     *
     * @throws UnavailabilityException
     */
    public function update(Unavailability $unavailability, array $data, int $changedByUserId, ?string $changeReason = null): Unavailability
    {
        $reasonType = isset($data['reason_type'])
            ? UnavailabilityReasonType::from($data['reason_type'])
            : $unavailability->reason_type;

        $driverId = array_key_exists('driver_id', $data) ? $data['driver_id'] : $unavailability->driver_id;
        $vehicleId = array_key_exists('vehicle_id', $data) ? $data['vehicle_id'] : $unavailability->vehicle_id;

        $this->validateSubjectAssignment($reasonType, $driverId, $vehicleId);

        return DB::transaction(function () use ($unavailability, $data, $changedByUserId, $changeReason): Unavailability {
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
                'changed_by_user_id' => $changedByUserId,
                'change_reason' => $changeReason,
            ]);

            $unavailability->update($data);

            return $unavailability->refresh();
        });
    }

    /**
     * Annule une indisponibilité (la rend inactive) et enregistre l'historique de cette annulation
     */
    public function cancel(Unavailability $unavailability, int $cancelledByUserId): Unavailability
    {
        return DB::transaction(function () use ($unavailability, $cancelledByUserId): Unavailability {
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
                'changed_by_user_id' => $cancelledByUserId,
                'change_reason' => 'Indisponibilite annulee.',
            ]);

            $unavailability->update(['is_active' => false]);

            return $unavailability->refresh();
        });
    }
}
