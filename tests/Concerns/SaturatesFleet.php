<?php

namespace Tests\Concerns;

use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Vehicle;

/**
 * Un chevauchement horaire ne bloque que si la flotte n'a plus de chauffeur ou de véhicule libre :
 * ce helper fait de la réservation donnée une réservation confirmée qui détient le seul chauffeur actif.
 */
trait SaturatesFleet
{
    protected function saturateFleet(Reservation $blocker, ReservationStatus $status = ReservationStatus::CONFIRME): Reservation
    {
        $driver = Driver::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        Driver::query()->whereKeyNot($driver->id)->update(['is_active' => false]);

        $blocker->update([
            'status' => $status,
            'driver_id' => $driver->id,
            'vehicle_id' => Vehicle::query()->where('is_active', true)->orderBy('id')->value('id'),
        ]);

        return $blocker;
    }
}
