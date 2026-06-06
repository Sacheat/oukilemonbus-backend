<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Demo\DemoActorResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vue chauffeur de la démo : réservations à venir et historique des 10 dernières.
 * Réservée aux utilisateurs avec le rôle chauffeur et un profil Driver associé.
 */
final class DemoDriverController extends DemoBaseController
{
    public function __construct(DemoActorResolver $actorResolver)
    {
        parent::__construct($actorResolver);
    }

    public function __invoke(Request $request): View
    {
        $actor = $this->actor($request);

        abort_unless(
            $actor->hasRole('chauffeur') && $actor->driver !== null,
            403,
            'Cette page est réservée aux chauffeurs.'
        );

        $driver = $actor->driver;

        $upcomingReservations = Reservation::query()
            ->with(['organization', 'pickupLocation', 'destinationLocation', 'vehicle'])
            ->where('driver_id', $driver->id)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->whereNotIn('status', [ReservationStatus::REFUSE->value, ReservationStatus::ANNULE->value])
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get();

        $pastReservations = Reservation::query()
            ->with(['organization', 'pickupLocation', 'destinationLocation', 'vehicle'])
            ->where('driver_id', $driver->id)
            ->whereDate('trip_date', '<', now()->toDateString())
            ->orderByDesc('trip_date')
            ->orderBy('departure_time')
            ->limit(10)
            ->get();

        return view('demo.driver.index', [
            ...$this->shared($request),
            'driver' => $driver,
            'upcomingReservations' => $upcomingReservations,
            'pastReservations' => $pastReservations,
        ]);
    }
}