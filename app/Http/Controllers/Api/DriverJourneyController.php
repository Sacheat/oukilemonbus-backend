<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripRouteResource;
use App\Models\Reservation;
use App\Services\DriverJourney\DriverJourneySettingsService;
use App\Services\DriverJourney\TripRouteService;
use App\Services\Reservations\TripProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Ma journée », partie 2 : réglages de la commune lus par le front et tracé réel d'un trajet.
 */
class DriverJourneyController extends Controller
{
    public function __construct(
        private readonly DriverJourneySettingsService $settings,
        private readonly TripRouteService $tripRouteService,
        private readonly TripProgressService $tripProgressService
    ) {}

    /** GET /driver-journey/settings : application de navigation, délai du premier départ, seuil des étapes */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forApi()]);
    }

    /**
     * GET /reservations/{reservation}/route : tracé et étapes, calculés à la première ouverture puis gardés.
     * `data` null si le tracé ne peut pas être calculé (coordonnées manquantes, service injoignable) : le front garde
     * la ligne à vol d'oiseau. Même droit de lecture que l'avancement : chauffeur affecté, agents et admins.
     */
    public function route(Request $request, Reservation $reservation): JsonResponse
    {
        $this->tripProgressService->ensureCanView($reservation, $request->user());

        $route = $this->tripRouteService->forReservation($reservation);

        return response()->json(['data' => $route ? new TripRouteResource($route) : null]);
    }
}
