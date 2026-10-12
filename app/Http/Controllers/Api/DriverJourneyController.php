<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\TripProgressResource;
use App\Http\Resources\TripRouteResource;
use App\Models\Reservation;
use App\Services\DriverJourney\DriverJourneySettingsService;
use App\Services\DriverJourney\TripRouteService;
use App\Services\Reservations\TripProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Ma journée » : journée du chauffeur en un appel, réglages de la commune lus par le front et tracé réel d'un trajet.
 */
class DriverJourneyController extends Controller
{
    public function __construct(
        private readonly DriverJourneySettingsService $settings,
        private readonly TripRouteService $tripRouteService,
        private readonly TripProgressService $tripProgressService
    ) {}

    /**
     * GET /driver-journey/day?date=AAAA-MM-JJ : tout ce qu'affiche « Ma journée » pour un jour, en un appel (le téléphone
     * du chauffeur est souvent sur un réseau faible) :
     *   missions  : ses trajets approuvés ou confirmés de ce jour, par heure de départ (même format que GET /reservations)
     *   progress  : avancement de chacun (même format que GET /trip-progress)
     *   previous_day / next_day : jour précédent et suivant qui ont une de ces missions (null sinon), pour les flèches
     *   settings  : réglages de « Ma journée » (même format que GET /driver-journey/settings)
     * Chauffeur seulement (trip-progress.record) : un compte sans profil chauffeur reçoit une journée vide.
     */
    public function day(Request $request): JsonResponse
    {
        $date = $request->validate(
            ['date' => ['required', 'date_format:Y-m-d']],
            ['date.required' => 'Indiquez le jour.', 'date.date_format' => 'Le jour doit être au format AAAA-MM-JJ.']
        )['date'];

        $user = $request->user();
        $own = fn () => Reservation::query()
            ->whereHas('driver', fn ($driver) => $driver->where('user_id', $user->getKey()))
            ->whereIn('status', [ReservationStatus::APPROUVE, ReservationStatus::CONFIRME]);

        $missions = $own()
            ->whereDate('trip_date', $date)
            ->with(['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts', 'councilDecision', 'tripEvents'])
            ->withMin('tripEvents', 'occurred_at')
            ->orderBy('departure_time')
            ->get();

        $day = fn ($value): ?string => $value ? substr((string) $value, 0, 10) : null;

        return response()->json([
            'data' => [
                'date' => $date,
                'missions' => ReservationResource::collection($missions)->resolve($request),
                'progress' => TripProgressResource::collection(
                    $missions->map(fn (Reservation $mission): array => $this->tripProgressService->summary($mission->tripEvents, $mission->id))
                )->resolve($request),
                'previous_day' => $day($own()->whereDate('trip_date', '<', $date)->max('trip_date')),
                'next_day' => $day($own()->whereDate('trip_date', '>', $date)->min('trip_date')),
                'settings' => $this->settings->forApi(),
            ],
        ]);
    }

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
