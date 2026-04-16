<?php

namespace App\Services\Unavailabilities;

use App\Enums\UnavailabilityReasonType;
use App\Exceptions\UnavailabilityException;
use App\Models\Unavailability;

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
     * @throws UnavailabilityException
     */
    public function update(Unavailability $unavailability, array $data): Unavailability
    {
        // On prend le reason_type de la requete ou celui deja en base
        $reasonType = isset($data['reason_type'])
            ? UnavailabilityReasonType::from($data['reason_type'])
            : $unavailability->reason_type;

        // On prend les ids de la requete ou ceux deja en base
        $driverId  = array_key_exists('driver_id', $data)  ? $data['driver_id']  : $unavailability->driver_id;
        $vehicleId = array_key_exists('vehicle_id', $data) ? $data['vehicle_id'] : $unavailability->vehicle_id;

        $this->validateSubjectAssignment($reasonType, $driverId, $vehicleId);

        $unavailability->update($data);

        return $unavailability->refresh();
    }

        public function cancel(Unavailability $unavailability, int $cancelledByUserId): Unavailability
    {
        $unavailability->update(['is_active' => false]);

        return $unavailability->refresh();
    }
}
