<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationConflictResource;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Services\Reservations\ReservationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gère la consultation et la résolution manuelle des conflits d'une réservation.
 * Réservé aux agents et admins (permission reservations.manage-conflicts).
 */
class ReservationConflictController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService
    ) {}

    public function index(Reservation $reservation): AnonymousResourceCollection
    {
        Gate::authorize('viewConflicts', $reservation);

        return ReservationConflictResource::collection(
            $reservation->conflicts()
                ->latest('created_at')
                ->paginate()
        );
    }

    public function resolve(Reservation $reservation, ReservationConflict $conflict): ReservationConflictResource
    {
        Gate::authorize('resolveConflict', $reservation);

        // Vérifie que le conflit appartient bien à cette réservation et non à une autre.
        abort_unless($conflict->reservation_id === $reservation->id, 404);

        return new ReservationConflictResource(
            $this->reservationService->resolveConflict($conflict, request()->user())
        );
    }
}
