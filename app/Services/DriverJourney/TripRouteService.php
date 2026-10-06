<?php

namespace App\Services\DriverJourney;

use App\Exceptions\GeocodingException;
use App\Models\Reservation;
use App\Models\TripRoute;
use App\Services\Locations\LocationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tracé réel d'un trajet, du lieu de départ à la destination (carte du détail d'une mission).
 *
 *   tracé gardé et lieux inchangés (même signature) → on le renvoie, sans appeler ORS
 *   pas de tracé, ou lieux changés                  → calcul ORS, enregistré (un par réservation)
 *   coordonnées manquantes ou ORS en panne          → null : le front garde la ligne à vol d'oiseau
 *
 * Calculé à la première ouverture seulement : ORS n'est appelé que pour les trajets réellement consultés.
 * Un échec est retenu FAILURE_RETRY_SECONDS : tant qu'ORS est en panne, les ouvertures suivantes ne le rappellent pas.
 */
class TripRouteService
{
    /** Délai avant de rappeler ORS pour un tracé qui vient d'échouer */
    private const FAILURE_RETRY_SECONDS = 180;

    public function __construct(
        private readonly LocationService $locationService
    ) {}

    public function forReservation(Reservation $reservation): ?TripRoute
    {
        $reservation->loadMissing(['pickupLocation', 'destinationLocation', 'tripRoute']);
        $signature = $this->signature($reservation);

        if ($signature === null) {
            return null;
        }

        if ($reservation->tripRoute !== null && $reservation->tripRoute->signature === $signature) {
            return $reservation->tripRoute;
        }

        $failureKey = 'trip-route-failed:'.$reservation->id.':'.md5($signature);

        if (Cache::has($failureKey)) {
            return null;
        }

        try {
            $route = $this->locationService->route(
                (float) $reservation->pickupLocation->latitude,
                (float) $reservation->pickupLocation->longitude,
                (float) $reservation->destinationLocation->latitude,
                (float) $reservation->destinationLocation->longitude
            );
        } catch (GeocodingException|Throwable $exception) {
            Log::info('Tracé du trajet non calculé', ['reservation_id' => $reservation->id, 'error' => $exception->getMessage()]);
            Cache::put($failureKey, true, self::FAILURE_RETRY_SECONDS);

            return null;
        }

        return TripRoute::query()->updateOrCreate(
            ['reservation_id' => $reservation->id],
            [...$route, 'signature' => $signature, 'provider' => 'openrouteservice', 'computed_at' => now()]
        );
    }

    /** Lieux et coordonnées du calcul ; null s'il manque des coordonnées */
    private function signature(Reservation $reservation): ?string
    {
        $from = $reservation->pickupLocation;
        $to = $reservation->destinationLocation;

        if ($from?->latitude === null || $from?->longitude === null || $to?->latitude === null || $to?->longitude === null) {
            return null;
        }

        return sprintf('%d:%.6F,%.6F>%d:%.6F,%.6F', $from->id, $from->latitude, $from->longitude, $to->id, $to->latitude, $to->longitude);
    }
}
