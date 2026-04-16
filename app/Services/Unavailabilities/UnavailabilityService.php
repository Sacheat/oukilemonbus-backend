<?php

namespace App\Services\Unavailabilities;

use App\Enums\UnavailabilityReasonType;
use App\Exceptions\UnavailabilityException;
use App\Models\Unavailability;
use Illuminate\Support\Carbon;

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
    public function create(array $data, int $createdByUserId): Unavailability
    {
        $reasonType = UnavailabilityReasonType::from($data['reason_type']);

        $this->validateSubjectAssignment(
            $reasonType,
            $data['driver_id'] ?? null,
            $data['vehicle_id'] ?? null
        );
        $this->validateDateRange($data['start_datetime'], $data['end_datetime']);

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

        $unavailability->update($data);

        return $unavailability->refresh();
    }

    public function cancel(Unavailability $unavailability, int $cancelledByUserId): Unavailability
    {
        $unavailability->update(['is_active' => false]);

        return $unavailability->refresh();
    }
}
